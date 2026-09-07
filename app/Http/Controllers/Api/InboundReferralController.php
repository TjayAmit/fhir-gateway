<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Fhir\Constants;
use App\Fhir\ReferralBundleReader;
use App\Http\Controllers\Controller;
use App\Models\Destination;
use App\Models\ReferralTask;
use App\Services\AuditLogger;
use App\Services\NativeSystemClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `POST /api/fhir` — a facility refers a patient to us.
 *
 * Flow 2. The gateway accepts an eReferral transaction Bundle, translates it back into the
 * intake contract, and hands it to the native system that should act on it.
 *
 * What the native system does next — which table, which status, who is paged — is deliberately
 * not the gateway's business, and the native contract is what makes that separation real. We
 * need a `201` and a record id; everything else belongs to them.
 *
 * Nothing clinical is stored here either. The Bundle is translated in flight and forwarded; the
 * only trace kept is workflow state in `referral_tasks`.
 */
class InboundReferralController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $bundle = $request->json()->all();

        if (($bundle['resourceType'] ?? null) !== 'Bundle' || ($bundle['type'] ?? null) !== 'transaction') {
            $this->audit->record($request, 'create', 400, $startedAt, 'Bundle');

            return $this->outcome('structure', 'Expected a Bundle of type "transaction".', 400);
        }

        try {
            $intake = (new ReferralBundleReader)->read($bundle);
        } catch (Throwable $e) {
            $this->audit->record($request, 'create', 422, $startedAt, 'Bundle');

            return $this->outcome('invalid', $e->getMessage(), 422);
        }

        // Who is this referral for? The performer organisation's NHFR code names one of our
        // facilities; the registry says which of our systems handles it.
        $nhfr = $intake['destination']['nhfr_code'] ?? null;

        $system = $nhfr === null
            ? null
            : Destination::query()->where('nhfr_code', $nhfr)->first();

        if ($system === null) {
            $this->audit->record($request, 'create', 404, $startedAt, 'Bundle', null, ['nhfr_code' => $nhfr]);

            return $this->outcome(
                'not-found',
                $nhfr === null
                    ? 'The referral names no receiving facility.'
                    : "Facility [{$nhfr}] is not one of ours.",
                404,
            );
        }

        if (! $system->canReceiveInbound()) {
            $this->audit->record($request, 'create', 503, $startedAt, 'Bundle', null, ['nhfr_code' => $nhfr]);

            // Refuse rather than accept something we cannot pass on. The sender can retry.
            return $this->outcome(
                'not-supported',
                "Facility [{$nhfr}] cannot accept referrals electronically yet.",
                503,
            );
        }

        $messageId = $intake['message_id'] ?? null;

        try {
            // `source.system` is not on the wire; the receiving system is the one we resolved.
            $intake['source']['system'] = $system->hcpn_id;

            $nativeId = (new NativeSystemClient($system))->deliverReferral($intake);
        } catch (Throwable $e) {
            $this->audit->record($request, 'create', 502, $startedAt, 'Bundle', $messageId, ['nhfr_code' => $nhfr]);

            return $this->outcome('exception', $e->getMessage(), 502);
        }

        $task = $this->recordTask($intake, $system, $nativeId);

        $this->audit->record(
            $request,
            'create',
            201,
            $startedAt,
            'Task',
            $task->fhir_task_id,
            ['message_id' => $messageId, 'nhfr_code' => $nhfr],
        );

        // A transaction Bundle is answered with a transaction-response.
        return response()->json([
            'resourceType' => 'Bundle',
            'type' => 'transaction-response',
            'entry' => [[
                'response' => [
                    'status' => '201 Created',
                    'location' => 'Task/'.$task->fhir_task_id,
                ],
            ]],
        ], 201)->header('Content-Type', 'application/fhir+json');
    }

    /**
     * Record the workflow state, and the one link back to the native record.
     *
     * The native id goes in `resource_identity_map` rather than on the task: that table exists
     * precisely to correlate a FHIR id with a native one, and this is the first flow that has
     * both. There is still nothing clinical in either row.
     *
     * @param  array<string, mixed>  $intake
     */
    private function recordTask(array $intake, Destination $system, string $nativeId): ReferralTask
    {
        $messageId = (string) ($intake['message_id'] ?? $nativeId);

        $task = ReferralTask::query()->updateOrCreate(
            ['fhir_task_id' => $messageId],
            [
                'direction' => ReferralTask::DIRECTION_INBOUND,
                'status' => 'received',
                'business_status' => Constants::WORKFLOW_RECEIVED,
                'requester_facility' => $intake['source']['facility_code'] ?? null,
                'performer_facility' => $system->nhfr_code,
            ],
        );

        if ($nativeId !== '') {
            DB::table('resource_identity_map')->updateOrInsert(
                ['fhir_resource_type' => 'Task', 'fhir_id' => $messageId],
                [
                    'source_system' => $system->hcpn_id,
                    'native_id' => $nativeId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return $task;
    }

    private function outcome(string $code, string $diagnostics, int $status): JsonResponse
    {
        return response()->json([
            'resourceType' => 'OperationOutcome',
            'issue' => [[
                'severity' => 'error',
                'code' => $code,
                'diagnostics' => $diagnostics,
            ]],
        ], $status)->header('Content-Type', 'application/fhir+json');
    }
}
