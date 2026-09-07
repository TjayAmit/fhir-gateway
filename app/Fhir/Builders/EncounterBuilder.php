<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * ERefEncounter — the visit at the sending facility that produced the referral.
 *
 * Built only when intake supplies `clinical.encounter`. We do not synthesise one from the
 * referral timestamp: an Encounter asserts that a clinical contact happened at a particular
 * time and place, and inventing that would be fabricating a record, not filling a field.
 */
final class EncounterBuilder
{
    /**
     * @param  array<string, mixed>  $encounter  the intake `clinical.encounter` block
     * @return array<string, mixed>
     */
    public static function build(array $encounter, string $patientRef, string $serviceProviderRef): array
    {
        $class = (string) ($encounter['class'] ?? 'AMB');

        return Element::prune([
            'resourceType' => 'Encounter',
            'meta' => ['profile' => [Constants::PROFILE_ENCOUNTER]],
            'status' => $encounter['status'] ?? 'finished',
            'class' => Element::coding(Constants::CS_V3_ACT_CODE, $class, self::classDisplay($class)),
            'subject' => Element::reference($patientRef),
            'period' => Element::prune([
                'start' => $encounter['start'] ?? null,
                'end' => $encounter['end'] ?? null,
            ]),
            'serviceProvider' => Element::reference($serviceProviderRef),
        ]);
    }

    private static function classDisplay(string $class): ?string
    {
        return match ($class) {
            'AMB' => 'ambulatory',
            'EMER' => 'emergency',
            'IMP' => 'inpatient encounter',
            'HH' => 'home health',
            'VR' => 'virtual',
            default => null,
        };
    }

    private function __construct() {}
}
