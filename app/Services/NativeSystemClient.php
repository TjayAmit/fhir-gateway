<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Destination;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls one of our own systems using the native contract (docs/native-contract.md).
 *
 * The mirror of FhirClient. That one speaks FHIR outward to facilities; this one speaks plain
 * intake JSON inward to the referral and telemedicine systems, so a native system never has to
 * parse a Bundle in either direction.
 *
 * Nothing returned here is stored. A patient looked up through this client is translated,
 * returned, and forgotten.
 */
final class NativeSystemClient
{
    public function __construct(private readonly Destination $system) {}

    /**
     * Hand a received referral to the native system.
     *
     * @param  array<string, mixed>  $intake  the referral in intake-contract shape
     * @return string the native record id the system assigned
     */
    public function deliverReferral(array $intake): string
    {
        if (! $this->system->canReceiveInbound()) {
            throw new RuntimeException(
                "System [{$this->system->hcpn_id}] has no inbound endpoint configured."
            );
        }

        $response = $this->request()
            // The same key the sender used, so a replay cannot open a second referral at
            // the far end either.
            ->withHeaders(['Idempotency-Key' => (string) ($intake['message_id'] ?? '')])
            ->post((string) $this->system->inbound_url, $intake);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Native system [%s] rejected the referral with HTTP %d: %s',
                $this->system->hcpn_id,
                $response->status(),
                mb_substr((string) $response->json('error', $response->body()), 0, 300),
            ));
        }

        return (string) ($response->json('native_id') ?? '');
    }

    /**
     * Ask whether the native system knows this patient.
     *
     * @return list<array<string, mixed>> patient blocks in intake-contract shape
     */
    public function findPatients(string $identifierSystem, string $identifierValue): array
    {
        if (! $this->system->canSearchPatients()) {
            return [];
        }

        $response = $this->request()->get((string) $this->system->patient_search_url, [
            'identifier' => $identifierSystem.'|'.$identifierValue,
        ]);

        $response->throw();

        return array_values(array_filter(
            (array) $response->json('data', []),
            static fn ($row): bool => is_array($row),
        ));
    }

    private function request(): PendingRequest
    {
        $request = Http::timeout((int) config('fhir.delivery.timeout', 30))->acceptJson();

        $key = $this->system->auth_credential_key;
        $token = $key === null ? '' : (string) config("services.native.{$key}", '');

        return $token === '' ? $request : $request->withToken($token);
    }
}
