<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * Intake `patient` block to an ERefPatient resource.
 *
 * The intake contract guarantees at least one of PhilSys or PhilHealth, so the identifier
 * array is never empty — that is what makes the conditional upsert in the bundle possible.
 */
final class PatientBuilder
{
    /**
     * @param  array<string, mixed>  $patient
     * @return array<string, mixed>
     */
    public static function build(array $patient): array
    {
        return Element::prune([
            'resourceType' => 'Patient',
            'meta' => ['profile' => [Constants::PROFILE_PATIENT]],
            'identifier' => self::identifiers($patient['identifiers'] ?? []),
            'active' => true,
            'name' => [
                Element::humanName(
                    (string) $patient['family_name'],
                    (array) ($patient['given_names'] ?? []),
                    $patient['suffix'] ?? null,
                ),
            ],
            'telecom' => isset($patient['mobile'])
                ? [Element::contactPoint('phone', (string) $patient['mobile'], 'mobile')]
                : null,
            'gender' => $patient['sex'] ?? null,
            'birthDate' => $patient['birth_date'] ?? null,
            'address' => isset($patient['address'])
                ? [self::address((array) $patient['address'])]
                : null,
        ]);
    }

    /**
     * Map intake identifier types onto their national system URIs.
     *
     * A `local` identifier is passed through under a configured system so nothing is lost,
     * but it carries no meaning outside our own estate.
     *
     * @param  list<array{type: string, value: string}>  $identifiers
     * @return list<array<string, mixed>>
     */
    public static function identifiers(array $identifiers): array
    {
        $systems = [
            'philsys' => Constants::ID_PHILSYS,
            'philhealth' => Constants::ID_PHILHEALTH,
            'local' => (string) config('fhir.local_identifier_system'),
        ];

        $out = [];

        foreach ($identifiers as $identifier) {
            $system = $systems[$identifier['type']] ?? null;

            if ($system === null || $system === '') {
                continue;
            }

            $out[] = Element::identifier($system, (string) $identifier['value']);
        }

        return $out;
    }

    /**
     * PH Core Address: PSGC codes ride as extensions, not as city/state strings.
     *
     * All four take valueCoding. Using valueCodeableConcept here is a hard validation error
     * and php-fhir will not warn you — this exact mistake cost a round of debugging in Phase 1.
     *
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public static function address(array $address): array
    {
        $levels = [
            'region_psgc' => Constants::EXT_REGION,
            'province_psgc' => Constants::EXT_PROVINCE,
            'city_psgc' => Constants::EXT_CITY,
            'barangay_psgc' => Constants::EXT_BARANGAY,
        ];

        $extensions = [];

        foreach ($levels as $field => $url) {
            if (empty($address[$field])) {
                continue;
            }

            $extensions[] = [
                'url' => $url,
                'valueCoding' => Element::coding(Constants::CS_PSGC, (string) $address[$field]),
            ];
        }

        return Element::prune([
            'extension' => $extensions,
            'use' => 'home',
            'line' => isset($address['line']) ? [(string) $address['line']] : null,
            'postalCode' => $address['postal_code'] ?? null,
            'country' => 'PH',
        ]);
    }

    private function __construct() {}
}
