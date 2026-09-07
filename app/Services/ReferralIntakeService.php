<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\IdentityConflictException;
use App\Fhir\ReferralBundleFactory;
use App\Models\Destination;
use App\Models\OutboundReferral;
use App\Models\ReferralTask;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Accepts a submission, translates it, and delivers it.
 *
 * Delivery is attempted inline rather than queued, and that is a deliberate consequence of
 * the no-persistence rule. Queuing means writing the payload somewhere — the jobs table, a
 * Redis list — and that payload is a patient's referral. Since the sending system owns the
 * native record, it is also the natural place for the payload to wait: a failed submission is
 * reported back, and the sender resubmits the same `message_id`, which re-attempts delivery
 * rather than opening a second referral.
 *
 * That keeps the gateway holding nothing and still gives the referral a way through.
 */
final class ReferralIntakeService
{
    public function __construct(private readonly ReferralBundleFactory $factory) {}

    /**
     * @param  array<string, mixed>  $intake  a validated intake payload
     * @return array{referral: OutboundReferral, replayed: bool}
     *
     * @throws IdentityConflictException
     */
    public function submit(array $intake): array
    {
        $messageId = (string) $intake['message_id'];

        $existing = OutboundReferral::query()->where('message_id', $messageId)->first();

        // A replay of something already delivered does nothing and returns the first result.
        if ($existing !== null && $existing->isTerminal()) {
            return ['referral' => $existing, 'replayed' => true];
        }

        $destination = Destination::query()
            ->where('hcpn_id', $intake['destination']['hcpn_id'])
            ->firstOrFail();

        $referral = $existing ?? new OutboundReferral;

        $referral->fill([
            'message_id' => $messageId,
            'source_system' => $intake['source']['system'],
            'native_id' => $intake['source']['record_id'],
            'request_type' => $intake['request_type'],
            'destination_hcpn_id' => $destination->hcpn_id,
            'target_facility' => $destination->nhfr_code,
            'target_endpoint' => $destination->endpoint_url,
            'status' => OutboundReferral::STATUS_SENDING,
        ]);

        $referral->attempts = $referral->attempts + 1;
        $referral->save();

        try {
            $this->deliver($intake, $destination, $referral);
        } catch (Throwable $e) {
            // An identity conflict is the sender's to resolve, not a transport failure, so it
            // propagates to the controller as a 409 instead of being recorded as a retry.
            if ($e instanceof IdentityConflictException) {
                $referral->forceFill([
                    'status' => OutboundReferral::STATUS_FAILED,
                    'last_error' => $e->getMessage(),
                ])->save();

                throw $e;
            }

            Log::warning('Referral delivery failed', [
                'message_id' => $messageId,
                'destination' => $destination->hcpn_id,
                'error' => $e->getMessage(),
            ]);

            $referral->forceFill([
                'status' => OutboundReferral::STATUS_FAILED,
                'last_error' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();
        }

        return ['referral' => $referral->refresh(), 'replayed' => false];
    }

    /**
     * @param  array<string, mixed>  $intake
     */
    private function deliver(array $intake, Destination $destination, OutboundReferral $referral): void
    {
        $client = new FhirClient($destination);

        // Ask the receiver who this patient is before deciding what to key the upsert on.
        $match = (new PatientResolver($client))->resolve($intake['patient']['identifiers']);

        $bundle = $this->factory->build($intake, $destination, $match);

        $response = $client->transaction($bundle);

        if ($response->failed()) {
            throw new \RuntimeException(
                "Receiver returned HTTP {$response->status()}: ".mb_substr($response->body(), 0, 500)
            );
        }

        $created = $this->locations($response->json() ?? []);

        $referral->forceFill([
            'status' => OutboundReferral::STATUS_DELIVERED,
            'fhir_task_id' => $created['Task'] ?? null,
            'fhir_service_request_id' => $created['ServiceRequest'] ?? null,
            'delivered_at' => now(),
            'last_error' => null,
        ])->save();

        ReferralTask::query()->updateOrCreate(
            ['fhir_task_id' => $created['Task'] ?? $referral->message_id],
            [
                'fhir_service_request_id' => $created['ServiceRequest'] ?? null,
                'direction' => ReferralTask::DIRECTION_OUTBOUND,
                'status' => 'requested',
                'requester_facility' => $intake['source']['facility_code'],
                'performer_facility' => $destination->nhfr_code,
                'patient_fhir_id' => $match->matchedResourceId ?? ($created['Patient'] ?? null),
            ],
        );
    }

    /**
     * Pull the ids the receiver assigned out of a transaction-response Bundle.
     *
     * Entries come back in request order with a `response.location` like
     * "Task/1234/_history/1". We only need the last id per resource type.
     *
     * @param  array<string, mixed>  $responseBundle
     * @return array<string, string>
     */
    private function locations(array $responseBundle): array
    {
        $out = [];

        foreach ($responseBundle['entry'] ?? [] as $entry) {
            $location = $entry['response']['location'] ?? null;

            if (! is_string($location)) {
                continue;
            }

            $parts = explode('/', trim($location, '/'));

            if (count($parts) >= 2) {
                $out[$parts[0]] = $parts[1];
            }
        }

        return $out;
    }
}
