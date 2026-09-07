<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\IdentityConflictException;
use App\Fhir\Constants;

/**
 * Works out which patient at the receiving facility this submission is about.
 *
 * The rule, settled in design review:
 *
 *   1. Look the patient up by PhilSys. If found, that is them.
 *   2. Otherwise look them up by PhilHealth. If found, that is them.
 *   3. If neither finds anyone, they are not registered there — the Bundle's conditional PUT
 *      will create them.
 *
 * The second lookup is a fallback, not a second opinion: it exists because a record created
 * before the both-IDs policy may carry only one of the two.
 *
 * Why not simply key the conditional PUT on PhilSys and let the receiver sort it out? Because
 * a PUT keyed on an identifier the receiver has never seen creates a new record, even when
 * they already hold that patient under the other identifier. The lookup is what stops the
 * gateway from manufacturing duplicates.
 *
 * No state is kept here. The gateway holds no patient index of its own (D1) — this asks the
 * receiving system, which is the only party that knows what it holds.
 */
final class PatientResolver
{
    public function __construct(private readonly FhirClient $client) {}

    /**
     * @param  list<array{type: string, value: string}>  $identifiers  from the intake payload
     *
     * @throws IdentityConflictException when the two identifiers resolve to different patients
     */
    public function resolve(array $identifiers): PatientMatch
    {
        $byType = [];

        foreach ($identifiers as $identifier) {
            $byType[$identifier['type']] = (string) $identifier['value'];
        }

        $philsys = $byType['philsys'] ?? null;
        $philhealth = $byType['philhealth'] ?? null;

        $philsysMatch = $philsys === null
            ? null
            : $this->findOne(Constants::ID_PHILSYS, $philsys);

        $philhealthMatch = $philhealth === null
            ? null
            : $this->findOne(Constants::ID_PHILHEALTH, $philhealth);

        // Both IDs found someone, and it is not the same someone.
        if ($philsysMatch !== null && $philhealthMatch !== null) {
            $philsysId = (string) ($philsysMatch['id'] ?? '');
            $philhealthId = (string) ($philhealthMatch['id'] ?? '');

            if ($philsysId !== $philhealthId) {
                throw new IdentityConflictException($philsysId, $philhealthId);
            }
        }

        if ($philsysMatch !== null) {
            return new PatientMatch(
                upsertSystem: Constants::ID_PHILSYS,
                upsertValue: (string) $philsys,
                isNew: false,
                matchedType: 'philsys',
                matchedResourceId: $philsysMatch['id'] ?? null,
                backfilled: $this->missingIdentifier($philsysMatch, Constants::ID_PHILHEALTH, $philhealth)
                    ? 'philhealth'
                    : null,
            );
        }

        if ($philhealthMatch !== null) {
            return new PatientMatch(
                upsertSystem: Constants::ID_PHILHEALTH,
                upsertValue: (string) $philhealth,
                isNew: false,
                matchedType: 'philhealth',
                matchedResourceId: $philhealthMatch['id'] ?? null,
                backfilled: $this->missingIdentifier($philhealthMatch, Constants::ID_PHILSYS, $philsys)
                    ? 'philsys'
                    : null,
            );
        }

        // Not registered at this facility. Key on PhilSys when we have it, since that is the
        // identifier every later referral will try first.
        return new PatientMatch(
            upsertSystem: $philsys !== null ? Constants::ID_PHILSYS : Constants::ID_PHILHEALTH,
            upsertValue: $philsys ?? (string) $philhealth,
            isNew: true,
        );
    }

    /**
     * A single search hit, or null. More than one hit is treated as no hit: an identifier that
     * matches two patients is a duplicate at the receiving end, and guessing between them is
     * exactly the mistake this class exists to avoid.
     *
     * @return array<string, mixed>|null
     */
    private function findOne(string $system, string $value): ?array
    {
        $matches = $this->client->searchByToken('Patient', 'identifier', $system, $value);

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * True when the matched record has no identifier in this system at all, so ours can be
     * added. A record that already carries a *different* value in that system is left alone —
     * overwriting it on the strength of one submission would propagate a typo into a chart.
     *
     * @param  array<string, mixed>  $patient
     */
    private function missingIdentifier(array $patient, string $system, ?string $ourValue): bool
    {
        if ($ourValue === null) {
            return false;
        }

        foreach ($patient['identifier'] ?? [] as $identifier) {
            if (($identifier['system'] ?? null) === $system) {
                return false;
            }
        }

        return true;
    }
}
