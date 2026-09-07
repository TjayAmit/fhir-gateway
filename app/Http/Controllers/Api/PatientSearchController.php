<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Fhir\Builders\PatientBuilder;
use App\Fhir\Constants;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateGatewayClient;
use App\Models\Destination;
use App\Services\AuditLogger;
use App\Services\NativeSystemClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

/**
 * `GET /api/fhir/Patient` — do we know this patient?
 *
 * Flow 1. The gateway holds no patient records, so it cannot answer this itself: it asks the
 * native systems over the native contract, translates whatever they return into PH Core
 * Patients, and forgets it again. Nothing is cached; a facade that caches demographics has
 * quietly become a repository.
 *
 * Three deliberate restrictions, all from D7:
 *
 *   1. **Identifier search only.** No name, no birth date, no wildcard. This endpoint answers
 *      "do you know *this* person", and a search surface that can be walked is a patient
 *      enumeration tool.
 *   2. **National identifiers only.** A local MRN means nothing to the caller asking.
 *   3. **Rate limited on the tightest bucket**, because even identifier search can be brute
 *      forced given enough attempts.
 */
class PatientSearchController extends Controller
{
    private const ALLOWED = ['identifier', '_count'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $startedAt = microtime(true);

        if ($unknown = array_diff(array_keys($request->query()), self::ALLOWED)) {
            $this->audit->record($request, 'search', 400, $startedAt, 'Patient', null, $request->query());

            return $this->outcome(
                'not-supported',
                'Unsupported search parameter(s): '.implode(', ', $unknown).
                '. This endpoint searches by identifier only — name and date-of-birth search are '.
                'deliberately not offered.',
                400,
            );
        }

        $identifier = (string) $request->query('identifier', '');

        if (! str_contains($identifier, '|')) {
            $this->audit->record($request, 'search', 400, $startedAt, 'Patient', null, $request->query());

            return $this->outcome(
                'required',
                'A single `identifier` parameter of the form system|value is required.',
                400,
            );
        }

        [$system, $value] = explode('|', $identifier, 2);

        $allowed = [Constants::ID_PHILSYS, Constants::ID_PHILHEALTH];

        if (! in_array($system, $allowed, true) || $value === '') {
            $this->audit->record($request, 'search', 400, $startedAt, 'Patient', null, $request->query());

            return $this->outcome(
                'value',
                'Search is limited to the national identifier systems: '.implode(' and ', $allowed).'.',
                400,
            );
        }

        $patients = [];
        $consulted = [];

        foreach ($this->searchableSystems() as $native) {
            try {
                foreach ((new NativeSystemClient($native))->findPatients($system, $value) as $row) {
                    // Native rows arrive in intake-contract shape, which is exactly what the
                    // Patient builder already consumes — no second mapping to keep in step.
                    $patients[] = PatientBuilder::build($row);
                }

                $consulted[] = $native->hcpn_id;
            } catch (Throwable $e) {
                // One system being down must not turn a partial answer into no answer, but the
                // caller has to know the result is partial rather than authoritative.
                report($e);
            }
        }

        $this->audit->record(
            $request,
            'search',
            200,
            $startedAt,
            'Patient',
            null,
            ['identifier' => $identifier],
            'systems='.implode(',', $consulted),
            count($patients),
        );

        return response()->json([
            'resourceType' => 'Bundle',
            'type' => 'searchset',
            'total' => count($patients),
            'entry' => array_map(static fn (array $patient): array => [
                'resource' => $patient,
                'search' => ['mode' => 'match'],
            ], $patients),
        ])->header('Content-Type', 'application/fhir+json');
    }

    /**
     * The native systems that can answer a patient lookup.
     *
     * A client only ever reaches its own facility's systems: the scope comes from the token,
     * and there is no parameter that widens it.
     *
     * @return Collection<int, Destination>
     */
    private function searchableSystems()
    {
        $client = request()->attributes->get(AuthenticateGatewayClient::ATTRIBUTE);
        $facility = is_array($client) ? $client['facility'] : null;

        return Destination::query()
            ->active()
            ->whereNotNull('patient_search_url')
            ->when($facility !== null, fn ($q) => $q->where('nhfr_code', $facility))
            ->get();
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
