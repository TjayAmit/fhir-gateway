<?php

declare(strict_types=1);

namespace App\Fhir;

/**
 * Canonical URLs, identifier systems and binding codes for PH Core and PH eReferral.
 *
 * Every value here was read out of the pinned tarballs in fhir-packages/, never from the
 * rendered IG pages. Four of them defeat pattern-matching, and were only caught because the
 * validator rejected what php-fhir had accepted in silence:
 *
 *   1. The PSA code systems live on psa.gov.ph, NOT under the IG's own base URL.
 *   2. PAN and PEN live on nhdr.gov.ph, NOT on philhealth.gov.ph like the PIN.
 *   3. eReferral ValueSet canonicals carry a "www." that its StructureDefinition URLs lack.
 *   4. The two IGs publish different canonicals for the same PSOC code system.
 *
 * Never derive one of these from another. Read it from the package, or take it from here.
 */
final class Constants
{
    // ------------------------------------------------------------------ IG bases

    public const PHCORE = 'https://fhir.doh.gov.ph/phcore';

    public const EREF = 'https://fhir.doh.gov.ph/pheref';

    /** eReferral ValueSets are published under www., unlike its StructureDefinitions. */
    public const EREF_VS = 'https://www.fhir.doh.gov.ph/pheref/ValueSet';

    // ------------------------------------------------------------------ Profiles

    public const PROFILE_PATIENT = self::EREF.'/StructureDefinition/ereferral-patient';

    public const PROFILE_ENCOUNTER = self::EREF.'/StructureDefinition/ereferral-encounter';

    public const PROFILE_CONDITION = self::EREF.'/StructureDefinition/ereferral-condition';

    public const PROFILE_OBSERVATION = self::EREF.'/StructureDefinition/ereferral-observation';

    public const PROFILE_SERVICE_REQUEST = self::EREF.'/StructureDefinition/ereferral-service-request';

    public const PROFILE_TASK = self::EREF.'/StructureDefinition/ereferral-task';

    public const PROFILE_PRACTITIONER_ROLE = self::EREF.'/StructureDefinition/ereferral-practitioner-role';

    public const PROFILE_PROVENANCE = self::EREF.'/StructureDefinition/ereferral-provenance';

    /** eReferral defines no Organization or Practitioner profile; it reuses PH Core directly. */
    public const PROFILE_ORGANIZATION = self::PHCORE.'/StructureDefinition/ph-core-organization';

    public const PROFILE_PRACTITIONER = self::PHCORE.'/StructureDefinition/ph-core-practitioner';

    // ------------------------------------------------------- Identifier systems

    public const ID_PHILSYS = 'http://philsys.gov.ph/fhir/Identifier/philsys-id';

    public const ID_PHILHEALTH = 'http://philhealth.gov.ph/fhir/Identifier/philhealth-id';

    public const ID_NHFR = self::PHCORE.'/Identifier/doh-nhfr-code';

    public const ID_HCPN = self::PHCORE.'/Identifier/hcpn-code';

    /** PAN and PEN sit on nhdr.gov.ph. Reading them off the PIN's host is the classic mistake. */
    public const ID_PAN = 'http://nhdr.gov.ph/fhir/Identifier/philhealthaccreditationnumber';

    public const ID_PEN = 'http://nhdr.gov.ph/fhir/Identifier/philhealthemployernumber';

    /** PRC licence. Taken verbatim from the IG's own submission bundle; the trailing slash is real. */
    public const ID_PRC = 'https://prc.gov.ph/';

    /** Our own referral number, carried on ServiceRequest.requisition. */
    public const ID_REQUISITION = 'urn:oid:1.2.840.113619.21.1.2';

    // ------------------------------------------------------------- Code systems

    public const CS_PSGC = 'https://psa.gov.ph/classification/psgc';

    public const CS_PSCED = 'https://psa.gov.ph/classification/psced/level';

    /**
     * PSOC. The two IGs disagree, and this is an upstream defect we have to live with.
     *
     * PH Core publishes   https://psa.gov.ph/classification/psoc/unit
     * eReferral publishes https://fhir.doh.gov.ph/phcore/CodeSystem/PSOC
     *
     * Same concept, same id, two URIs. Our rule: use the eReferral URI when the code goes into
     * a resource bound to an eReferral ValueSet (PractitionerRole.code), and the PH Core URI
     * everywhere else (Patient occupation). Using the wrong one fails a required binding.
     */
    public const CS_PSOC_PHCORE = 'https://psa.gov.ph/classification/psoc/unit';

    public const CS_PSOC_EREF = self::PHCORE.'/CodeSystem/PSOC';

    public const CS_PHCW = self::PHCORE.'/CodeSystem/PHCW';

    public const CS_EREF_WORKFLOW = self::EREF.'/CodeSystem/ereferral-workflow';

    public const CS_SNOMED = 'http://snomed.info/sct';

    public const CS_LOINC = 'http://loinc.org';

    /** UCUM. Required on any Quantity that lands under a base FHIR vital-signs profile. */
    public const CS_UCUM = 'http://unitsofmeasure.org';

    public const CS_ICD10 = 'http://hl7.org/fhir/sid/icd-10';

    public const CS_V3_ACT_CODE = 'http://terminology.hl7.org/CodeSystem/v3-ActCode';

    public const CS_V3_ROLE_CODE = 'http://terminology.hl7.org/CodeSystem/v3-RoleCode';

    public const CS_CONDITION_CLINICAL = 'http://terminology.hl7.org/CodeSystem/condition-clinical';

    public const CS_OBSERVATION_CATEGORY = 'http://terminology.hl7.org/CodeSystem/observation-category';

    public const CS_PROVENANCE_ROLE = 'http://terminology.hl7.org/CodeSystem/provenance-participant-type';

    // ------------------------------------------------------- PH Core extensions

    /** These four take valueCoding. The demographic extensions take valueCodeableConcept. */
    public const EXT_REGION = self::PHCORE.'/StructureDefinition/region';

    public const EXT_PROVINCE = self::PHCORE.'/StructureDefinition/province';

    public const EXT_CITY = self::PHCORE.'/StructureDefinition/city-municipality';

    public const EXT_BARANGAY = self::PHCORE.'/StructureDefinition/barangay';

    // -------------------------------------------------- Required binding: category

    /**
     * ServiceRequest.category, required binding to the Referral Category ValueSet.
     * The ValueSet admits exactly these two SNOMED codes; anything else is a hard error.
     *
     * @var array<string, array{code: string, display: string}>
     */
    public const REFERRAL_CATEGORY = [
        'emergency' => ['code' => '73770003', 'display' => 'Emergency'],
        'outpatient' => ['code' => '440655000', 'display' => 'Outpatient'],
    ];

    /**
     * ServiceRequest.reasonCode, required binding to Reason for Referral (Service Type).
     * Again, exactly four admissible codes.
     *
     * @var array<string, array{code: string, display: string}>
     */
    public const SERVICE_TYPE = [
        'consultation' => ['code' => '11429006', 'display' => 'Consultation'],
        'diagnostics' => ['code' => '165197003', 'display' => 'Diagnostics'],
        'procedure' => ['code' => '71388002', 'display' => 'Procedure'],
        'others' => ['code' => '3457005', 'display' => 'Others'],
    ];

    /**
     * PractitionerRole.code, the SNOMED half of the eReferral practitioner role ValueSet.
     *
     * @var array<string, array{code: string, display: string}>
     */
    public const PRACTITIONER_ROLE = [
        'physician' => ['code' => '158965000', 'display' => 'Doctor'],
        'nurse' => ['code' => '265937000', 'display' => 'Nurse'],
        'midwife' => ['code' => '309453006', 'display' => 'Midwife'],
        'pharmacist' => ['code' => '46255001', 'display' => 'Pharmacist'],
        'medical-technologist' => ['code' => '386629007', 'display' => 'Medical Technologist'],
        'laboratory-aide' => ['code' => '159282002', 'display' => 'Laboratory Aide'],
        'dentist' => ['code' => '106289002', 'display' => 'Dentist'],
        'dental-aide' => ['code' => '4162009', 'display' => 'Dental Aide'],
        'optometrist' => ['code' => '28229004', 'display' => 'Optometrist'],
    ];

    /** Task.code. The IG's own submission bundle uses this SNOMED concept for the referral itself. */
    public const TASK_CODE_REFERRAL = ['code' => '3457005', 'display' => 'Patient referral'];

    // -------------------------------------------------------- Workflow states

    /** Task.businessStatus, the eReferral workflow state machine. */
    public const WORKFLOW_RECEIVED = 'received';

    public const WORKFLOW_ACCEPTED = 'accepted';

    public const WORKFLOW_REJECTED = 'rejected';

    public const WORKFLOW_REFERRED_ONWARD = 'referred-onward';

    public const WORKFLOW_CAPACITY_FULL = 'capacity-full';

    /** FHIR RequestPriority. Intake uses these verbatim, so no mapping table is needed. */
    public const PRIORITIES = ['routine', 'urgent', 'asap', 'stat'];

    private function __construct() {}
}
