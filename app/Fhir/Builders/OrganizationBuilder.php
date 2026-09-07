<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;

/**
 * PH Core Organization — a facility, identified by its DOH NHFR code.
 *
 * eReferral publishes no Organization profile of its own, so this is PH Core's directly.
 * Invariant org-1 needs a name or an identifier; we always have the NHFR code, so a
 * facility whose name we do not know still produces a valid resource.
 */
final class OrganizationBuilder
{
    /**
     * @return array<string, mixed>
     */
    public static function build(
        string $nhfrCode,
        ?string $name = null,
        ?string $hcpnCode = null,
        ?string $phone = null,
    ): array {
        $identifiers = [Element::identifier(Constants::ID_NHFR, $nhfrCode)];

        if ($hcpnCode !== null && $hcpnCode !== '') {
            $identifiers[] = Element::identifier(Constants::ID_HCPN, $hcpnCode);
        }

        return Element::prune([
            'resourceType' => 'Organization',
            'meta' => ['profile' => [Constants::PROFILE_ORGANIZATION]],
            'identifier' => $identifiers,
            'active' => true,
            'name' => $name,
            // org-2 and org-3 forbid use=home on telecom and address.
            'telecom' => $phone !== null && $phone !== ''
                ? [Element::contactPoint('phone', $phone, 'work')]
                : null,
        ]);
    }

    private function __construct() {}
}
