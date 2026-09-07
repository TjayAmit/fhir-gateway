# PH Core IG — Compacted Context

> Source of truth: <https://build.fhir.org/ig/niccoreyes/ph-core/en/> (CI build, fork)
> Official/published: <https://fhir.doh.gov.ph/phcore/index.html>
> Other CI branch: <https://build.fhir.org/ig/UP-Manila-SILab/ph-core/>
> Captured: 2026-09-07. Status: **Draft, not for production.** Verify any exact URL/slice against the IG before coding.

## 1. Identity

| Field | Value |
|---|---|
| Name | Philippine Core (PH Core) Implementation Guide |
| Package id | `fhir.ph.core` |
| Version | `0.2.0` (draft; `0.1.0` was the last tagged) |
| FHIR version | **R4 — 4.0.1** |
| Canonical | `https://fhir.doh.gov.ph/phcore/ImplementationGuide/fhir.ph.core` |
| Canonical base for artifacts | `https://fhir.doh.gov.ph/phcore/...` |
| Publisher | UP Manila National TeleHealth Center, under DOH, technical assistance from CSIRO |
| Dependencies | HL7 Terminology (THO) 7.2.0, FHIR Extensions Pack 5.3.0, FHIR Tooling Extensions 1.1.2 |

**Role:** national base layer. It defines *what a Filipino patient/provider/facility looks like in FHIR*. It is deliberately **not** workflow-specific — downstream IGs (eReferral, NHDR/PhilHealth) derive from it.

## 2. Resource profiles (22)

Canonical pattern: `https://fhir.doh.gov.ph/phcore/StructureDefinition/ph-core-<name>`

`ph-core-allergyintolerance`, `ph-core-claim`, `ph-core-condition`, `ph-core-encounter`,
`ph-core-healthcareservice`, `ph-core-immunization`, `ph-core-location`, `ph-core-medication`,
`ph-core-medicationadministration`, `ph-core-medicationdispense`, `ph-core-medicationrequest`,
`ph-core-medicationstatement`, `ph-core-observation`, `ph-core-organization`, `ph-core-patient`,
`ph-core-practitioner`, `ph-core-practitionerrole`, `ph-core-procedure`, `ph-core-provenance`,
`ph-core-relatedperson`, `ph-core-serviceRequest` *(note the capital R in the id)*, `ph-core-task`

### Datatype profiles
| Profile | id |
|---|---|
| PH Core Address | `ph-core-address` (PSGC-coded region/province/city/barangay via extensions) |
| PH Core Name | `ph-core-name` |
| PhilHealth ID (PIN) | `ph-core-philhealth-id` |
| PhilHealth Accreditation No. (PAN) | `ph-core-philhealth-pan` |
| PhilHealth Employer No. (PEN) | `ph-core-philhealth-pen` |
| PhilSys ID | `ph-core-philsys-id` |
| DOH NHFR Code | `ph-core-doh-nhfr-code` |
| HCPN Code | `ph-core-hcpn-code` |

## 3. Extensions

Canonical pattern: `https://fhir.doh.gov.ph/phcore/StructureDefinition/<id>`

`region`, `province`, `city-municipality`, `barangay` (address, PSGC-coded),
`indigenous-group`, `indigenous-people`, `race`, `occupation`, `educational-attainment`,
`ph-core-pwd-disability`, `administered-product`, `batch-number`

Reused HL7 extensions on Patient: `patient-nationality`, `patient-religion`,
`individual-genderIdentity`, `individual-recordedSexOrGender` (last two cited for SOGIE Bill alignment).

## 4. Identifier systems (critical for a gateway)

**Verified 2026-09-07 by extracting `package/NamingSystem-*.json` from `ph-core.tgz` 0.2.0.**
These are authoritative — earlier values taken from the rendered IG pages were incomplete.

| Identifier | System URI | NamingSystem |
|---|---|---|
| PhilSys ID (National ID) | `http://philsys.gov.ph/fhir/Identifier/philsys-id` | `PhilSysIDNS` |
| PhilHealth PIN | `http://philhealth.gov.ph/fhir/Identifier/philhealth-id` | `PhilHealthIDNS` |
| DOH NHFR facility code | `https://fhir.doh.gov.ph/phcore/Identifier/doh-nhfr-code` | `DOHNHFRCodeNS` |
| HCPN code | `https://fhir.doh.gov.ph/phcore/Identifier/hcpn-code` | `HCPNCodeNS` |
| PhilHealth PAN | `http://nhdr.gov.ph/fhir/Identifier/philhealthaccreditationnumber` | `PhilHealthPANNS` |
| PhilHealth PEN | `http://nhdr.gov.ph/fhir/Identifier/philhealthemployernumber` | `PhilHealthPENNS` |
| PhilHealth Procedure ID | `http://philhealth.gov.ph/procedure` | `PhilHealthProcedureIDNS` |
| DOH Procedure ID | `https://fhir.doh.gov.ph/phcore/NamingSystem/procedure-id` | `DOHProcedureIDNS` |

⚠️ **PAN and PEN live on `nhdr.gov.ph`, not `philhealth.gov.ph`** — easy to get wrong by pattern-matching
the PIN. Note also the inconsistent `http` vs `https` schemes; they are not normalisable, use them verbatim.

All NamingSystems are `status: draft`. Treat these URIs as **configurable constants**, not hardcoded literals.

## 5. Terminology

**Verified 2026-09-07 from `ph-core.tgz` 0.2.0.** The PSA code systems are **not** in the
`fhir.doh.gov.ph` namespace — do not assume the canonical follows the IG's base URL.

| CodeSystem | Canonical (system URI) |
|---|---|
| PSGC (geographic) | `https://psa.gov.ph/classification/psgc` |
| PSOC (occupation) | `https://psa.gov.ph/classification/psoc/unit` |
| PSCED (education) | `https://psa.gov.ph/classification/psced/level` |
| PH FDA CPR | `https://verification.fda.gov.ph` |
| Indigenous groups | `https://fhir.doh.gov.ph/phcore/CodeSystem/indigenous-groups-cs` |
| Disability type | `https://fhir.doh.gov.ph/phcore/CodeSystem/ph-core-disability-type-cs` |

**ValueSets** *are* under the IG base — `https://fhir.doh.gov.ph/phcore/ValueSet/{id}`:
`regions`, `provinces`, `cities`, `barangays`, `occupational-classifications`,
`educational-attainments`, `indigenous-groups-vs`, `ph-core-disability-type-vs`, `drugs-vs`.

⚠️ PSGC/PSOC/PSCED are mocks in v0.2.0 — content is not resolvable, so every coded element produces a
validator **warning**, not an error. Confirmed in Phase 1. Plan a terminology abstraction.

### Extension value types — get these right

Validated against the package. The address extensions take `Coding`; the demographic ones take
`CodeableConcept`. Using the wrong one is a hard validation **error**, and php-fhir will not catch it.

| Extension | `value[x]` type |
|---|---|
| `region`, `province`, `city-municipality`, `barangay` | **`Coding`** |
| `indigenous-group`, `educational-attainment`, `race` | `CodeableConcept` |
| `occupation` | complex (sub-extensions, no `value[x]`) |

## 6. Key profile detail

### PH Core Patient — `.../StructureDefinition/ph-core-patient`
- identifier sliced: `PHCorePhilHealthID`, `PHCorePhilSysID` (both 0..*)
- MS: `name` (PHCoreName), `telecom`, `gender`, `birthDate`, `address` (PHCoreAddress), `maritalStatus`, `contact`, `communication.language` (1..1)
- Bindings: gender / maritalStatus / contact.relationship = **required** (FHIR VS); race = required (v3-Race); indigenousGroup = required (PH VS); educationalAttainment = extensible; religion, language = preferred
- birthDate precision guidance: `YYYY`, `YYYY-MM`, `YYYY-MM-DD`; year-only displays default to Jan 1

### PH Core Organization — `.../StructureDefinition/ph-core-organization`
- identifier sliced open-by-system: `NhfrCode`, `HcpnCode`, `PAN`, `PEN`
- MS: `identifier`, `active`, `type`, `name`, `telecom`, `address` (PHCoreAddress), `partOf`, `contact.purpose`, `contact.address`
- Invariants: `org-1` (name or identifier required), `org-2`/`org-3` (no `use=home`)
- Referenced by nearly every other PH Core profile — the org directory is the hub

## 7. Conformance model — **no CapabilityStatement**

PH Core uses **ActorDefinitions + element obligations** instead of a CapabilityStatement:

| Actor | Meaning |
|---|---|
| `Creator` | System producing PH Core resources — obligations are mostly *MAY populate* |
| `Server` | "FHIR server that stores and provides access to PH Core resources in response to FHIR API requests" — covers HIEs, national repositories, facility servers. *SHALL handle* the MS elements |
| `Consumer` | System reading resources — *SHALL handle* the MS elements |

**Implication for the gateway:** PH Core gives us no REST contract. We must author our own CapabilityStatement and declare which actor(s) the gateway plays (it will play **Server** to clients and **Creator/Consumer** upstream).

## 8. Examples worth reading
`Bundle-bundle-acs-case-example` (full ACS clinical case), `Bundle-transaction-example`,
`Composition-composition-ed-note-example`, plus ~60 single-resource examples.
Sample case walkthrough: `sample-case.html`.

## 9. Gotchas
1. Draft + moving. Two active CI forks (`niccoreyes`, `UP-Manila-SILab`) — pin one.
2. `ph-core-serviceRequest` has non-standard camelCase in its id.
3. Mock terminology means validation against expanded value sets will produce warnings.
4. Package not on a public FHIR registry yet — pull `package.tgz` from the IG build output and vendor it.
