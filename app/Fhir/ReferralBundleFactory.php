<?php

declare(strict_types=1);

namespace App\Fhir;

use App\Fhir\Builders\ConditionBuilder;
use App\Fhir\Builders\EncounterBuilder;
use App\Fhir\Builders\ObservationBuilder;
use App\Fhir\Builders\OrganizationBuilder;
use App\Fhir\Builders\PatientBuilder;
use App\Fhir\Builders\PractitionerBuilder;
use App\Fhir\Builders\PractitionerRoleBuilder;
use App\Fhir\Builders\ProvenanceBuilder;
use App\Fhir\Builders\ServiceRequestBuilder;
use App\Fhir\Builders\TaskBuilder;
use App\Models\Destination;
use App\Services\PatientMatch;
use RuntimeException;

/**
 * Turns one validated intake request into a submission Bundle.
 *
 * This is the whole point of the gateway: everything above it speaks plain JSON, everything
 * below it speaks PH Core and eReferral, and the seam is here.
 *
 * Entries are added in dependency order — a resource cannot reference a urn:uuid that has
 * not been minted yet.
 */
final class ReferralBundleFactory
{
    /**
     * @param  array<string, mixed>  $intake  a validated intake payload
     * @param  PatientMatch|null  $match  the result of resolving the patient at the receiver.
     *                                    When given, its identifier keys the conditional PUT
     *                                    so the referral lands on the record the receiver
     *                                    already holds. Without it we fall back to PhilSys,
     *                                    which is correct only when the receiver is unknown
     *                                    to us — as in the golden fixtures.
     * @return array<string, mixed>
     */
    public function build(array $intake, Destination $destination, ?PatientMatch $match = null): array
    {
        $bundle = new BundleAssembler;

        $authoredOn = (string) $intake['sent_at'];
        $source = $intake['source'];
        $patient = $intake['patient'];
        $clinical = $intake['clinical'] ?? [];

        // --- Identity: conditional upserts on national identifiers ------------------

        [$patientSystem, $patientValue] = $match !== null
            ? [$match->upsertSystem, $match->upsertValue]
            : $this->patientKey($patient['identifiers']);

        $patientRef = $bundle->upsert(
            PatientBuilder::build($patient),
            $patientSystem,
            $patientValue,
        );

        $practitionerRef = $bundle->upsert(
            PractitionerBuilder::build($source['practitioner']),
            Constants::ID_PRC,
            (string) $source['practitioner']['prc_license'],
        );

        $sourceOrgRef = $bundle->upsert(
            OrganizationBuilder::build(
                (string) $source['facility_code'],
                $source['facility_name'] ?? null,
            ),
            Constants::ID_NHFR,
            (string) $source['facility_code'],
        );

        $destinationOrgRef = $bundle->upsert(
            OrganizationBuilder::build(
                $destination->nhfr_code,
                $destination->display_name,
                $destination->hcpn_code,
                $destination->phone,
            ),
            Constants::ID_NHFR,
            $destination->nhfr_code,
        );

        $requesterRef = $bundle->upsert(
            PractitionerRoleBuilder::build(
                (string) $source['practitioner']['prc_license'],
                $practitionerRef,
                $sourceOrgRef,
                (string) ($source['practitioner']['role'] ?? 'physician'),
            ),
            Constants::ID_PRC,
            (string) $source['practitioner']['prc_license'],
        );

        // --- Clinical context -------------------------------------------------------

        $encounterRef = null;

        if (! empty($clinical['encounter'])) {
            $encounterRef = $bundle->create(
                EncounterBuilder::build($clinical['encounter'], $patientRef, $sourceOrgRef)
            );
        }

        $reasonReferences = [];

        foreach ($clinical['diagnoses'] ?? [] as $diagnosis) {
            $reasonReferences[] = $bundle->create(
                ConditionBuilder::build($diagnosis, $patientRef, $authoredOn, $encounterRef)
            );
        }

        $supportingInfo = [];

        foreach ($clinical['vitals'] ?? [] as $vital) {
            $supportingInfo[] = $bundle->create(
                ObservationBuilder::build($vital, $patientRef, $encounterRef)
            );
        }

        // --- The request and its workflow wrapper -----------------------------------

        $details = $this->requestDetails($intake);

        $serviceRequestRef = $bundle->create(
            ServiceRequestBuilder::build(
                recordId: (string) $source['record_id'],
                category: $details['category'],
                serviceType: $details['service_type'],
                priority: $details['priority'],
                subjectRef: $patientRef,
                requesterRef: $requesterRef,
                performerRef: $destinationOrgRef,
                authoredOn: $authoredOn,
                reasonText: $clinical['reason_text'] ?? null,
                encounterRef: $encounterRef,
                occurrenceDateTime: $details['occurrence_date_time'],
                reasonReferences: $reasonReferences,
                supportingInfo: $supportingInfo,
                note: $this->note($clinical['notes'] ?? null, $details['note']),
                serviceRequested: $details['service_requested'],
                specialty: $details['specialty'],
            )
        );

        $bundle->createIfNoneExist(
            TaskBuilder::build(
                messageId: (string) $intake['message_id'],
                focusRef: $serviceRequestRef,
                patientRef: $patientRef,
                requesterRef: $requesterRef,
                ownerRef: $destinationOrgRef,
                authoredOn: $authoredOn,
                reasonText: $clinical['reason_text'] ?? null,
            ),
            (string) config('fhir.message_identifier_system'),
            (string) $intake['message_id'],
        );

        // Provenance targets the ServiceRequest only — the eReferral profile does not allow
        // a Task target, even though the Task is what carries the workflow.
        $bundle->create(
            ProvenanceBuilder::build(
                [$serviceRequestRef],
                $requesterRef,
                $authoredOn,
                $sourceOrgRef,
            )
        );

        return $bundle->toArray();
    }

    /**
     * Join the clinician's own note to anything the translation needs to say in words.
     */
    private function note(?string $clinicalNote, ?string $translationNote): ?string
    {
        $parts = array_filter([$clinicalNote, $translationNote], static fn (?string $p): bool => $p !== null && $p !== '');

        return $parts === [] ? null : implode("\n\n", $parts);
    }

    /**
     * Choose the identifier the patient upsert is keyed on.
     *
     * PhilSys first, PhilHealth as the fallback — the same order the resolution flow uses, so
     * the conditional PUT lands on the same record a lookup would have found.
     *
     * @param  list<array{type: string, value: string}>  $identifiers
     * @return array{0: string, 1: string}
     */
    private function patientKey(array $identifiers): array
    {
        $byType = [];

        foreach ($identifiers as $identifier) {
            $byType[$identifier['type']] = (string) $identifier['value'];
        }

        if (isset($byType['philsys'])) {
            return [Constants::ID_PHILSYS, $byType['philsys']];
        }

        if (isset($byType['philhealth'])) {
            return [Constants::ID_PHILHEALTH, $byType['philhealth']];
        }

        // Unreachable through the intake validator, which requires one of the two.
        throw new RuntimeException('Patient has no national identifier to key the upsert on.');
    }

    /**
     * Normalise the two request shapes into the fields the ServiceRequest needs.
     *
     * A referral happens at a point in time. A telemedicine consult is requested for a window,
     * and here the IG does not stretch to fit: ERefServiceRequest slices `occurrence[x]` CLOSED
     * on occurrenceDateTime, so an occurrencePeriod is a hard validation error. The eReferral
     * profiles were written for referrals, not for scheduled consults.
     *
     * Until that is resolved (open risk: telemedicine may belong in Appointment, which the IG
     * does not profile), the window's start becomes the occurrence and the full window is
     * carried in a note. Nothing is silently dropped — a human reading the referral sees both
     * ends of the window, and the machine-readable half is honest about being a single instant.
     *
     * @param  array<string, mixed>  $intake
     * @return array{category: string, service_type: string, priority: string, occurrence_date_time: ?string, note: ?string, service_requested: ?string, specialty: ?string}
     */
    private function requestDetails(array $intake): array
    {
        if ($intake['request_type'] === 'telemedicine') {
            $tele = $intake['telemedicine'];
            $window = $tele['preferred_windows'][0] ?? null;

            $note = 'Telemedicine consult requested. Modality: '.($tele['modality'] ?? 'unspecified').'.';

            if ($window !== null) {
                $note .= ' Preferred window: '.$window['start'].' to '.$window['end'].'.';
            }

            return [
                'category' => 'outpatient',
                'service_type' => 'consultation',
                'priority' => (string) ($tele['priority'] ?? 'routine'),
                'occurrence_date_time' => $window === null ? null : (string) $window['start'],
                'note' => $note,
                'service_requested' => 'Telemedicine consultation ('.($tele['modality'] ?? 'unspecified').')',
                'specialty' => $tele['specialty'] ?? null,
            ];
        }

        $referral = $intake['referral'];

        return [
            'category' => (string) $referral['category'],
            'service_type' => (string) $referral['service_type'],
            'priority' => (string) ($referral['priority'] ?? 'routine'),
            'occurrence_date_time' => (string) $intake['sent_at'],
            'note' => null,
            'service_requested' => $referral['service_requested'] ?? null,
            'specialty' => $referral['specialty'] ?? null,
        ];
    }
}
