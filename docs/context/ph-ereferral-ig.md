# PH eReferral IG — Compacted Context

> Source of truth: <https://build.fhir.org/ig/ph-ereferral-organization/ph-ereferral/en/>
> Captured: 2026-09-07. Status: **Draft v0.1.0 — "a draft testing baseline, not a production deployment specification."**

## 1. Identity

| Field | Value |
|---|---|
| Name | Philippine eReferral Implementation Guide |
| Package id | `fhir.ph.ereferral` |
| Version | `0.1.0` (published 2026-06-20, CI build) |
| FHIR version | **R4 — 4.0.1** |
| Canonical | `https://fhir.doh.gov.ph/pheref/ImplementationGuide/fhir.ph.ereferral` |
| Canonical base for artifacts | `https://fhir.doh.gov.ph/pheref/...` |
| Publisher | SILab CoP IG Accelerator (eReferral), oversight by DOH |
| Depends on | **PH Core (`fhir.ph.core`, current)**, THO 7.2.0, Extensions Pack 5.3.0 |
| Legal basis | Universal Health Care Act (RA 11223); DOH AO 2020-0019 |

**Role:** the workflow layer. Electronic referral between facilities inside a **Health Care Provider Network (HCPN)**. Derived from **WHO SMART Guidelines L1** (see `who-smart-l1.html`).

## 2. Profiles (12) — all derive from PH Core

Canonical pattern: `https://fhir.doh.gov.ph/pheref/StructureDefinition/<id>`

| Profile | id | Base |
|---|---|---|
| ERefServiceRequest | `ereferral-service-request` | PHCoreServiceRequest |
| ERefTask | `ereferral-task` | PHCoreTask |
| ERefPatient | `ereferral-patient` | PHCorePatient |
| ERefEncounter | `ereferral-encounter` | PHCoreEncounter |
| ERefPractitionerRole | `ereferral-practitioner-role` | PHCorePractitionerRole |
| EReferral Condition | `ereferral-condition` | PHCoreCondition |
| EReferral Observation | `ereferral-observation` | PHCoreObservation |
| EReferral Procedure | `ereferral-procedure` | PHCoreProcedure |
| EReferral MedicationAdministration | `ereferral-medication-administration` | PHCoreMedicationAdministration |
| ERefImmunization | `ereferral-immunization` | PHCoreImmunization |
| EReferral Provenance | `ereferral-provenance` | PHCoreProvenance |
| EReferral RelatedPerson | `ereferral-related-person` | PHCoreRelatedPerson |

Extension: `ereferral-pwd-disability`.
**No Organization or Practitioner profile of its own** — it reuses PH Core directly.

## 3. Terminology

| Artifact | Canonical |
|---|---|
| eReferral Workflow CodeSystem | `https://fhir.doh.gov.ph/pheref/CodeSystem/ereferral-workflow` |
| Receiving Facility Response VS | `https://fhir.doh.gov.ph/pheref/ValueSet/ereferral-receiving-response` |
| Referral Category VS | `.../ValueSet/referral-category` (id `vs-referral-category`) |
| Reason for Referral (Service Type) VS | `.../ValueSet/reason-for-referral-service-type` |
| Practitioner Role VS | `.../ValueSet/vs-practitioner-role` |
| eReferral Relationship Type VS | `.../ValueSet/ereferral-relationship-type` |
| PWD Disability Type VS / CS | `.../pheref/ValueSet/pwd-disability-type-vs`, `.../pheref/CodeSystem/pwd-disability-type-cs` |
| PHCW CodeSystem | `https://fhir.doh.gov.ph/`**`phcore`**`/CodeSystem/PHCW` ⚠️ |
| PSOC CodeSystem | `https://fhir.doh.gov.ph/`**`phcore`**`/CodeSystem/PSOC` ⚠️ |

⚠️ **Namespace collision — verified 2026-09-07 from the packages.** The eReferral package defines
`PHCW` and `PSOC` into the **`phcore`** namespace, not its own `pheref` namespace. Worse, its `PSOC`
canonical (`https://fhir.doh.gov.ph/phcore/CodeSystem/PSOC`) **differs from PH Core's own PSOC**
(`https://psa.gov.ph/classification/psoc/unit`) — same concept, same id, two different system URIs
across the two IGs.

This is an upstream IG defect. Consequence for us: **never infer a CodeSystem URI from the IG base URL
or from the code system id.** Read the canonical from the package. When coding occupation, decide
explicitly which of the two PSOC URIs to emit and record the choice.

### eReferral Workflow codes — the heart of the state machine

| Code | Meaning |
|---|---|
| `received` | Receiving facility acknowledged receipt, reviewing whether it can take the case |
| `accepted` | Receiving facility can take the case — positive transfer/service response |
| `rejected` | Cannot take the case, **no** onward facility identified |
| `referred-onward` | Cannot take the case, **directs** patient/referral to another named facility |
| `capacity-full` | Receiving facility reports capacity is full |
| `onward-referral-request` | The next ServiceRequest created after an onward referral |
| `consultation-summary` | Summary of the referral service outcome |

The `ereferral-receiving-response` ValueSet includes only: `received`, `accepted`, `rejected`, `referred-onward`.

## 4. Logical information model (8 groups)

| # | Group | Maps to |
|---|---|---|
| 1 | Patient identity | ERefPatient, `ServiceRequest.subject`, `Task.for` |
| 2 | Sending context | ERefPractitionerRole, Practitioner, Organization, `ServiceRequest.requester` |
| 3 | Receiving context | Organization, PractitionerRole, `ServiceRequest.performer`, `Task.owner` |
| 4 | Referral request | ERefServiceRequest: `code`, `category`, `priority`, `authoredOn`, `occurrence[x]` |
| 5 | Clinical reason | `reasonCode`, `reasonReference` to Condition, Observation |
| 6 | Clinical context and prior care | `supportingInfo` to Observation, Condition, Procedure, MedicationAdministration, Immunization |
| 7 | Workflow and response tracking | ERefTask: `status`, `businessStatus`, `statusReason`, `output` |
| 8 | Audit and provenance | ERefProvenance, `ServiceRequest.relevantHistory` |

## 5. ERefServiceRequest — key constraints

- `intent` **fixed to `order`**
- `subject` 1..1 to ERefPatient; `requester` 1..1 to ERefPractitionerRole
- `performer` 0..* to PH Core Organization | ERefPractitionerRole
- `category` — **required** binding to Referral Category VS
- `reasonCode` — **required** binding to Reason for Referral (Service Type) VS
- `code` — example binding (SNOMED procedure codes)
- `encounter` to ERefEncounter; `supportingInfo` to the eReferral clinical profiles
- `replaces` to ERefServiceRequest — used for onward-referral chaining
- `relevantHistory` to ERefProvenance
- Invariant `ereferral-requester-has-role` (warning): if PractitionerRole is used, facility info should be resolvable

## 6. ERefTask — key constraints

- `status` 1..1 (standard FHIR TaskStatus: draft | requested | received | accepted | rejected | ready | cancelled | in-progress | on-hold | failed | completed | entered-in-error)
- `intent` fixed `order`
- `focus` 1..1 to ERefServiceRequest
- `for` to ERefPatient; `requester` to PractitionerRole; `owner` to Practitioner | PractitionerRole | Organization (receiving facility or care navigator)
- `businessStatus` — carries the eReferral workflow sub-state (**example** binding in v0.1; weak, expect it to tighten)
- `statusReason` — why held / failed / refused
- `output` — value[x], CodeableConcept primary; the referral outcome
- `authoredOn`, `lastModified` must-support

## 7. Workflow — 10 steps, 6 actors

**Actors:** referring facility/initiator, referring practitioner, receiving facility/service, receiving clinician, care navigator (optional), referral management system / FHIR server.

1. Patient presents; demographics captured
2. Initiator assesses, determines referral need
3. Criteria not met — end, no eReferral record
4. Discuss with patient; consent per local policy *(consent deferred in v0.1)*
5. **Create** ServiceRequest with clinical summary
6. **Send** — select receiving facility; Task created
7. Receiving service registers and evaluates; create/update Task
8. Record decision using `received` / `accepted` / `rejected` / `referred-onward`
9. Route for clinical review; send updates via Task
10. Close — Task `completed`, record outcome and end time

### Connectathon minimum path (5 steps)

| Step | Expected state |
|---|---|
| Create | ServiceRequest `status=active`, `intent=order`, `priority=urgent`, subject, requester, receiving facility, reasonCode, supporting Observations |
| Send | Task `requested`, `focus` to ServiceRequest |
| Receive | Task `accepted`, `owner` = receiving facility |
| Respond | Task stays `accepted` (richer semantics pending issue #47) |
| Close | Task `completed` with `output`; receiving Encounter created |

## 8. Exchange shape — the submission bundle

`Bundle-ExampleERefSubmissionBundle` — **`type = transaction`, no Composition**, 21 entries, `urn:uuid:` fullUrls:

- `PUT` (conditional upsert) for identity resources: Patient (by PhilSys ID), 2x Practitioner (by PRC ID), 2x Organization (by DOH NHFR code), 2x PractitionerRole
- `POST` for the clinical/workflow payload: ServiceRequest, Encounter, 2x Condition, 6x Observation, Procedure, DiagnosticReport, Task, Provenance

**This is the single most important artifact for gateway design** — the wire format is a FHIR transaction Bundle with identifier-based upserts, not a message or a document.

## 9. Explicitly out of scope in v0.1 — our design gaps

| Deferred by the IG | Consequence for the gateway |
|---|---|
| Transport behaviour undefined | We choose: referral management system, shared server, or exchange layer |
| Auth, authz, transport security, data protection | **We must design this ourselves** |
| Attachment exchange | No Binary / DocumentReference pattern yet |
| Consent | Not modelled |
| Facility directory / HCPN routing rules | No lookup service specified — we need one |
| Back-referral | Not modelled end-to-end |
| Non-response / SLA escalation | No timers or escalation defined |
| Rich receiving-facility response states | Issue #47 / PR #84 pending |
| Versioned release publication | Issue #73 pending |
| Multi-server connectathon harness | Not available |
| No CapabilityStatement, SearchParameters, OperationDefinitions | We author our own REST contract |

## 10. Guidance pages worth re-reading

`who-smart-l1.html`, `referral-workflow.html`, `logical-information-model.html`,
`connectathon-readiness.html`, `v01-scope.html`, `coverage-map.html`, `decision-log.html`,
`data-dictionary.html`, `sample-case-ana-reyes.html` (Kalibo Health Center to Dr. Rafael S. Tumbokon Memorial Hospital, severe pre-eclampsia).
