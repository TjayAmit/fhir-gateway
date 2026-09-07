<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * PH Core Practitioner — the referring clinician, keyed on their PRC licence.
 *
 * The PRC number is the only identifier on a practitioner the receiving facility can
 * independently verify, which is why it is the key for the conditional upsert.
 */
final class PractitionerBuilder
{
    /**
     * @param  array<string, mixed>  $practitioner  the intake `source.practitioner` block
     * @return array<string, mixed>
     */
    public static function build(array $practitioner): array
    {
        $telecom = [];

        if (! empty($practitioner['phone'])) {
            $telecom[] = Element::contactPoint('phone', (string) $practitioner['phone'], 'work');
        }

        if (! empty($practitioner['email'])) {
            $telecom[] = Element::contactPoint('email', (string) $practitioner['email'], 'work');
        }

        return Element::prune([
            'resourceType' => 'Practitioner',
            'meta' => ['profile' => [Constants::PROFILE_PRACTITIONER]],
            'identifier' => [
                Element::identifier(Constants::ID_PRC, (string) $practitioner['prc_license']),
            ],
            'active' => true,
            'name' => [
                Element::humanName(
                    (string) $practitioner['family_name'],
                    (array) ($practitioner['given_names'] ?? []),
                ),
            ],
            'telecom' => $telecom,
        ]);
    }

    private function __construct() {}
}
