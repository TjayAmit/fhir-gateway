<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\IdentityConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\IntakeRequest;
use App\Models\OutboundReferral;
use App\Services\AuditLogger;
use App\Services\ReferralIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The gateway's front door for our own systems.
 *
 * Everything here speaks the intake contract, not FHIR. A sender never sees a Bundle, a
 * profile or a canonical URL — that is the whole point of the gateway existing.
 */
class IntakeController extends Controller
{
    public function __construct(
        private readonly ReferralIntakeService $intake,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * POST /intake/v1/requests
     */
    public function store(IntakeRequest $request): JsonResponse
    {
        $startedAt = microtime(true);
        $payload = $request->validated();

        try {
            ['referral' => $referral, 'replayed' => $replayed] = $this->intake->submit($payload);
        } catch (IdentityConflictException $e) {
            $this->record($request, $payload, 409, $startedAt);

            return response()->json($e->toOperationOutcome(), 409);
        }

        $this->record($request, $payload, $replayed ? 200 : 202, $startedAt, $referral);

        // 200 for a replay: nothing new happened, here is what happened the first time.
        // 202 for a fresh submission: accepted, and delivery has been attempted.
        return response()->json(
            $referral->toStatusArray(),
            $replayed ? 200 : 202,
        );
    }

    /**
     * GET /intake/v1/requests/{messageId}
     */
    public function show(Request $request, string $messageId): JsonResponse
    {
        $referral = OutboundReferral::query()->where('message_id', $messageId)->first();

        if ($referral === null) {
            return response()->json([
                'resourceType' => 'OperationOutcome',
                'issue' => [[
                    'severity' => 'error',
                    'code' => 'not-found',
                    'diagnostics' => "No submission found for message_id [{$messageId}].",
                ]],
            ], 404);
        }

        return response()->json($referral->toStatusArray());
    }

    /**
     * Record the request. The patient identifiers land in the audit query by design — a trail
     * that cannot say which patient was involved does not do its job.
     *
     * @param  array<string, mixed>  $payload
     */
    private function record(
        Request $request,
        array $payload,
        int $status,
        float $startedAt,
        ?OutboundReferral $referral = null,
    ): void {
        $this->audit->record(
            request: $request,
            action: 'create',
            httpStatus: $status,
            startedAt: $startedAt,
            resourceType: 'ServiceRequest',
            resourceId: $referral?->fhir_service_request_id,
            query: [
                'message_id' => $payload['message_id'] ?? null,
                'request_type' => $payload['request_type'] ?? null,
                'destination' => $payload['destination']['hcpn_id'] ?? null,
                'patient_identifiers' => $payload['patient']['identifiers'] ?? [],
            ],
        );
    }
}
