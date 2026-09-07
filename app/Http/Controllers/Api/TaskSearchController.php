<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Fhir\Builders\TaskViewBuilder;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateGatewayClient;
use App\Models\ReferralTask;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/fhir/Task` — referral status, as FHIR.
 *
 * The first genuinely FHIR-shaped read surface. Two rules govern it, and both come from D7:
 *
 *   1. **The scope filter is injected, never accepted.** A caller sees Tasks their own facility
 *      sent or received. There is no query parameter that widens that, and a client asking for
 *      another facility's referrals is answered with their own.
 *   2. **Search parameters are allow-listed.** Anything unrecognised is refused outright rather
 *      than ignored — silently dropping a filter would return more than the caller asked for,
 *      which on patient data is the wrong way to fail.
 */
class TaskSearchController extends Controller
{
    /** Everything this endpoint understands. */
    private const ALLOWED = ['status', 'business-status', '_count', '_sort'];

    private const MAX_COUNT = 200;

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        /** @var array{id: string, facility: ?string, systems: list<string>} $client */
        $client = $request->attributes->get(AuthenticateGatewayClient::ATTRIBUTE);

        if ($unknown = array_diff(array_keys($request->query()), self::ALLOWED)) {
            $this->audit->record($request, 'search', 400, $startedAt, 'Task', null, $request->query());

            return $this->outcome(
                'not-supported',
                'Unsupported search parameter(s): '.implode(', ', $unknown).
                '. This endpoint supports: '.implode(', ', self::ALLOWED).'.',
                400,
            );
        }

        $facility = $client['facility'];

        if ($facility === null || $facility === '') {
            $this->audit->record($request, 'search', 403, $startedAt, 'Task', null, $request->query());

            return $this->outcome(
                'forbidden',
                'This client has no facility scope configured, so no referrals can be returned.',
                403,
            );
        }

        $query = ReferralTask::query()
            // The scope filter. Injected from the token; no query parameter can remove it.
            ->where(function ($q) use ($facility): void {
                $q->where('requester_facility', $facility)
                    ->orWhere('performer_facility', $facility);
            });

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', (string) $request->query('status')));
        }

        if ($request->filled('business-status')) {
            $query->whereIn('business_status', explode(',', (string) $request->query('business-status')));
        }

        $count = min((int) $request->query('_count', '50'), self::MAX_COUNT);

        $tasks = $query
            ->orderBy($request->query('_sort') === 'authored' ? 'created_at' : 'updated_at', 'desc')
            ->limit(max($count, 1))
            ->get();

        $this->audit->record(
            $request,
            'search',
            200,
            $startedAt,
            'Task',
            null,
            $request->query(),
            'facility='.$facility,
            $tasks->count(),
        );

        return response()->json([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => $tasks->count(),
            'entry' => $tasks->map(fn (ReferralTask $task): array => [
                'fullUrl' => url('/api/fhir/Task/'.$task->fhir_task_id),
                'resource' => TaskViewBuilder::build($task),
            ])->all(),
        ])->header('Content-Type', 'application/fhir+json');
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $startedAt = microtime(true);

        /** @var array{id: string, facility: ?string, systems: list<string>} $client */
        $client = $request->attributes->get(AuthenticateGatewayClient::ATTRIBUTE);
        $facility = $client['facility'];

        $task = ReferralTask::query()
            ->where('fhir_task_id', $id)
            ->where(function ($q) use ($facility): void {
                $q->where('requester_facility', $facility)
                    ->orWhere('performer_facility', $facility);
            })
            ->first();

        // Out of scope and not found are answered identically on purpose. Distinguishing them
        // would confirm that a referral exists to a caller with no right to know that.
        if ($task === null) {
            $this->audit->record($request, 'read', 404, $startedAt, 'Task', $id, [], 'facility='.$facility);

            return $this->outcome('not-found', "No Task [{$id}] is visible to this client.", 404);
        }

        $this->audit->record($request, 'read', 200, $startedAt, 'Task', $id, [], 'facility='.$facility, 1);

        return response()->json(TaskViewBuilder::build($task))
            ->header('Content-Type', 'application/fhir+json');
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
        ], $status);
    }
}
