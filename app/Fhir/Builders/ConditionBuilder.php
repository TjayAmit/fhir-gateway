<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * EReferral Condition — one diagnosis from the intake `clinical.diagnoses` array.
 *
 * A diagnosis with no code is allowed through as text only. Refusing a referral because the
 * sending clerk had no ICD-10 handy would be worse for the patient than sending it uncoded,
 * and the receiving clinician can still read it.
 */
final class ConditionBuilder
{
    /**
     * @param  array{system?: string, code?: string, text?: string}  $diagnosis
     * @return array<string, mixed>
     */
    public static function build(array $diagnosis, string $patientRef, string $recordedDate, ?string $encounterRef = null): array
    {
        return Element::prune([
            'resourceType' => 'Condition',
            'meta' => ['profile' => [Constants::PROFILE_CONDITION]],
            'clinicalStatus' => Element::conceptOf(
                Constants::CS_CONDITION_CLINICAL,
                'active',
                'Active',
            ),
            'code' => self::code($diagnosis),
            'subject' => Element::reference($patientRef),
            'encounter' => $encounterRef !== null ? Element::reference($encounterRef) : null,
            'recordedDate' => $recordedDate,
        ]);
    }

    /**
     * @param  array{system?: string, code?: string, text?: string}  $diagnosis
     * @return array<string, mixed>
     */
    private static function code(array $diagnosis): array
    {
        $system = match ($diagnosis['system'] ?? 'text') {
            'icd10' => Constants::CS_ICD10,
            'snomed' => Constants::CS_SNOMED,
            default => null,
        };

        $codings = [];

        if ($system !== null && ! empty($diagnosis['code'])) {
            $codings[] = Element::coding($system, (string) $diagnosis['code'], $diagnosis['text'] ?? null);
        }

        return Element::concept($codings, $diagnosis['text'] ?? null);
    }

    private function __construct() {}
}
