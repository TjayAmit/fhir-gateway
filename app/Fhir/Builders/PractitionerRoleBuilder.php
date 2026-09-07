<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;
use InvalidArgumentException;

/**
 * ERefPractitionerRole — binds a practitioner to the facility they are acting for.
 *
 * ServiceRequest.requester is 1..1 and must point at one of these, not at a bare
 * Practitioner, so every referral needs one even when the role is obvious.
 */
final class PractitionerRoleBuilder
{
    /**
     * @param  string  $practitionerRef  urn:uuid reference to the Practitioner entry
     * @param  string  $organizationRef  urn:uuid reference to the Organization entry
     * @param  string  $role  an intake role key, e.g. "physician"
     * @return array<string, mixed>
     */
    public static function build(
        string $prcLicense,
        string $practitionerRef,
        string $organizationRef,
        string $role = 'physician',
    ): array {
        $coding = Constants::PRACTITIONER_ROLE[$role] ?? null;

        if ($coding === null) {
            throw new InvalidArgumentException(
                "Unknown practitioner role [{$role}]. Allowed: ".
                implode(', ', array_keys(Constants::PRACTITIONER_ROLE))
            );
        }

        return Element::prune([
            'resourceType' => 'PractitionerRole',
            'meta' => ['profile' => [Constants::PROFILE_PRACTITIONER_ROLE]],
            'identifier' => [Element::identifier(Constants::ID_PRC, $prcLicense)],
            'active' => true,
            'practitioner' => Element::reference($practitionerRef),
            'organization' => Element::reference($organizationRef),
            'code' => [
                Element::conceptOf(Constants::CS_SNOMED, $coding['code'], $coding['display']),
            ],
        ]);
    }

    private function __construct() {}
}
