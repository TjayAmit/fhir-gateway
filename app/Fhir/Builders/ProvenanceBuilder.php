<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * EReferral Provenance — who asserted this referral, and when.
 *
 * The profile restricts `target` to ServiceRequest, so this attests to the referral itself
 * and not to the Task that tracks it. Pointing it at the Task as well is a validation error;
 * that was caught here by the validator, not by php-fhir, which serialised it happily.
 */
final class ProvenanceBuilder
{
    /**
     * @param  list<string>  $targetRefs  urn:uuid refs to the resources this attests to
     * @return array<string, mixed>
     */
    public static function build(array $targetRefs, string $authorRef, string $recorded, ?string $organizationRef = null): array
    {
        $agents = [
            Element::prune([
                'type' => Element::conceptOf(Constants::CS_PROVENANCE_ROLE, 'author', 'Author'),
                'who' => Element::reference($authorRef),
            ]),
        ];

        if ($organizationRef !== null) {
            $agents[] = Element::prune([
                'type' => Element::conceptOf(Constants::CS_PROVENANCE_ROLE, 'custodian', 'Custodian'),
                'who' => Element::reference($organizationRef),
            ]);
        }

        return Element::prune([
            'resourceType' => 'Provenance',
            'meta' => ['profile' => [Constants::PROFILE_PROVENANCE]],
            'target' => array_map(
                static fn (string $ref): array => Element::reference($ref),
                $targetRefs,
            ),
            'recorded' => $recorded,
            'agent' => $agents,
        ]);
    }

    private function __construct() {}
}
