<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * EReferral Observation — one reading from the intake `clinical.vitals` array.
 *
 * Two deliberate accommodations for what native systems actually hold:
 *
 *   - If no LOINC code is supplied, the observation is coded by text alone. The binding on
 *     Observation.code is example-strength, so this validates.
 *   - A value like "140/90" is not a Quantity. Rather than invent systolic and diastolic
 *     components from a string, a non-numeric value is emitted as valueString. Splitting it
 *     properly needs the native system to send the two numbers separately.
 */
final class ObservationBuilder
{
    /**
     * @param  array{name: string, value: string|int|float, unit?: string, code?: string, system?: string, taken_at?: string}  $vital
     * @return array<string, mixed>
     */
    public static function build(array $vital, string $patientRef, ?string $encounterRef = null): array
    {
        return Element::prune([
            'resourceType' => 'Observation',
            'meta' => ['profile' => [Constants::PROFILE_OBSERVATION]],
            'status' => 'final',
            'category' => [
                Element::conceptOf(
                    Constants::CS_OBSERVATION_CATEGORY,
                    'vital-signs',
                    'Vital Signs',
                ),
            ],
            'code' => self::code($vital),
            'subject' => Element::reference($patientRef),
            'encounter' => $encounterRef !== null ? Element::reference($encounterRef) : null,
            'effectiveDateTime' => $vital['taken_at'] ?? null,
            ...self::value($vital),
        ]);
    }

    /**
     * @param  array<string, mixed>  $vital
     * @return array<string, mixed>
     */
    private static function code(array $vital): array
    {
        $codings = [];

        if (! empty($vital['code'])) {
            $codings[] = Element::coding(
                (string) ($vital['system'] ?? Constants::CS_LOINC),
                (string) $vital['code'],
                (string) $vital['name'],
            );
        }

        return Element::concept($codings, (string) $vital['name']);
    }

    /**
     * @param  array<string, mixed>  $vital
     * @return array<string, mixed>
     */
    private static function value(array $vital): array
    {
        $raw = $vital['value'] ?? null;

        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_numeric($raw)) {
            $unit = $vital['unit'] ?? null;

            return [
                'valueQuantity' => Element::prune([
                    'value' => (float) $raw,
                    'unit' => $unit,
                    // A LOINC-coded vital sign pulls in the base FHIR vital-signs profile,
                    // which requires a UCUM-coded Quantity — a bare `unit` string fails it.
                    // The intake contract therefore specifies units in UCUM.
                    'system' => $unit !== null ? Constants::CS_UCUM : null,
                    'code' => $unit,
                ]),
            ];
        }

        $text = (string) $raw;

        if (! empty($vital['unit'])) {
            $text .= ' '.$vital['unit'];
        }

        return ['valueString' => $text];
    }

    private function __construct() {}
}
