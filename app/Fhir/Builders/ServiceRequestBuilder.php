<?php

declare(strict_types=1);

namespace App\Fhir\Builders;

use App\Fhir\Constants;
use App\Fhir\Support\Element;
use InvalidArgumentException;

/**
 * ERefServiceRequest — the referral itself.
 *
 * Two elements carry REQUIRED bindings and are the easiest way to fail validation:
 * `category` admits only Emergency or Outpatient, and `reasonCode` only Consultation,
 * Diagnostics, Procedure or Others. Free text goes in `.text` beside the coding, never
 * instead of it. `intent` is fixed to `order` by the profile.
 *
 * `occurrence[x]` takes a dateTime and nothing else: the profile slices it CLOSED on
 * occurrenceDateTime, so an occurrencePeriod fails validation. That is why a telemedicine
 * appointment window cannot be carried here — see ReferralBundleFactory::requestDetails().
 */
final class ServiceRequestBuilder
{
    /**
     * @param  list<string>  $reasonReferences  urn:uuid refs to Condition / Observation
     * @param  list<string>  $supportingInfo  urn:uuid refs to supporting clinical resources
     * @return array<string, mixed>
     */
    public static function build(
        string $recordId,
        string $category,
        string $serviceType,
        string $priority,
        string $subjectRef,
        string $requesterRef,
        string $performerRef,
        string $authoredOn,
        ?string $reasonText = null,
        ?string $encounterRef = null,
        ?string $occurrenceDateTime = null,
        array $reasonReferences = [],
        array $supportingInfo = [],
        ?string $note = null,
        ?string $serviceRequested = null,
        ?string $specialty = null,
    ): array {
        $categoryCoding = Constants::REFERRAL_CATEGORY[$category] ?? null;

        if ($categoryCoding === null) {
            throw new InvalidArgumentException(
                "Unknown referral category [{$category}]. Allowed: ".
                implode(', ', array_keys(Constants::REFERRAL_CATEGORY))
            );
        }

        $reasonCoding = Constants::SERVICE_TYPE[$serviceType] ?? null;

        if ($reasonCoding === null) {
            throw new InvalidArgumentException(
                "Unknown service type [{$serviceType}]. Allowed: ".
                implode(', ', array_keys(Constants::SERVICE_TYPE))
            );
        }

        if (! in_array($priority, Constants::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                "Unknown priority [{$priority}]. Allowed: ".implode(', ', Constants::PRIORITIES)
            );
        }

        return Element::prune([
            'resourceType' => 'ServiceRequest',
            'meta' => ['profile' => [Constants::PROFILE_SERVICE_REQUEST]],
            // The native record id travels here, so the receiver can quote it back to us
            // and a human on either side can trace the referral to its source record.
            'requisition' => Element::identifier(Constants::ID_REQUISITION, $recordId),
            'status' => 'active',
            'intent' => 'order',
            'category' => [
                Element::conceptOf(
                    Constants::CS_SNOMED,
                    $categoryCoding['code'],
                    $categoryCoding['display'],
                    $categoryCoding['display'],
                ),
            ],
            'priority' => $priority,
            // What was actually asked for, in the requester's words. The binding on .code is
            // example-strength, so uncoded text is valid — and it is all the native systems
            // give us. Leaving it out entirely was dropping the one field a receiving clerk
            // most wants to read.
            'code' => $serviceRequested !== null && $serviceRequested !== ''
                ? ['text' => $serviceRequested]
                : null,
            'performerType' => $specialty !== null && $specialty !== ''
                ? ['text' => $specialty]
                : null,
            'subject' => Element::reference($subjectRef),
            'encounter' => $encounterRef !== null ? Element::reference($encounterRef) : null,
            'occurrenceDateTime' => $occurrenceDateTime,
            'authoredOn' => $authoredOn,
            'requester' => Element::reference($requesterRef),
            'performer' => [Element::reference($performerRef)],
            // The coded reason satisfies the binding; the clinician's own words go in .text.
            'reasonCode' => [
                Element::conceptOf(
                    Constants::CS_SNOMED,
                    $reasonCoding['code'],
                    $reasonCoding['display'],
                    $reasonText ?? $reasonCoding['display'],
                ),
            ],
            'reasonReference' => array_map(
                static fn (string $ref): array => Element::reference($ref),
                $reasonReferences,
            ),
            'supportingInfo' => array_map(
                static fn (string $ref): array => Element::reference($ref),
                $supportingInfo,
            ),
            'note' => $note !== null && $note !== '' ? [['text' => $note]] : null,
        ]);
    }

    private function __construct() {}
}
