# Tasks

Architecture and decisions: [overview.md](overview.md) · Plan: [plan.html](plan.html) ·
Intake contract: [intake-contract.md](intake-contract.md) · IG reference: [context/](context/)

## Progress

Last updated 2026-09-07.

| Phase | Status | Done |
|---|---|---|
| 0 — Verify the package | ✅ **Complete** | 3 / 3 |
| 1 — Setup | ✅ **Complete** | 9 / 9 |
| 2 — PH Core layer | ✅ **Complete** | 6 / 6 |
| 3 — Flows | 🟡 **First slice done** | 2 / 4 |
| 4 — Policy layer | 🟡 In progress | 2 / 5 |

**Working today**

- `POST /api/intake/v1/requests` — plain JSON in, PH eReferral transaction Bundle out, delivered
- `GET /api/registry/v1/hcpn` — destination picklist; unreachable facilities are not selectable
- `GET /api/intake/v1/requests/{message_id}` — delivery status
- `php artisan fhir:build-referral <intake.json> --out=<bundle.json>` — translate without sending
- `php artisan fhir:validate <file> --profile=<canonical>` — conformance check
- Patient identity resolved at the receiver: PhilSys → PhilHealth → register; `409` on conflict
- **4 golden fixtures at 0 errors**, all gated in CI
- 70 tests green, Pint green, PHPStan level 7 green

**Needs a decision from you**

| # | Question |
|---|---|
| 1 | The React starter kit (Inertia, React, Fortify, Wayfinder) is unused by a headless gateway. Strip it? |
| 2 | Confirm the `409` refusal on conflicting identifiers (implemented as the safe default) |
| 3 | Telemedicine as `ServiceRequest` is confirmed lossy for scheduling windows — accept, or model `Appointment`? |
| 4 | The blocked items at the bottom, with the native system owners |

---

## Phase 0 — Verify the package ✅ PASSED

- [x] Parse `Bundle-ExampleERefSubmissionBundle` with `php-fhir` R4, re-serialize, diff
- [x] Check survivors: primitive extensions (`_birthDate`), `urn:uuid` references, choice types
- [x] **Gate: PASSED** — canonical hash identical, all PH artifacts preserved

Full results: [phase-0-findings.md](phase-0-findings.md) · harness kept in `tools/roundtrip/`

Two caveats carried forward:
- **Unknown fields are silently dropped** (no error) → the CI validator is non-optional
- **Decimal precision lost** (`1.50` → `1.5`), not fixable in-library → accepted, documented

---

## Phase 1 — Setup ✅ COMPLETE

- [x] Laravel project — 13.30.1 at `fhir-gateway/`
- [x] Vendor `dcarbone/php-fhir-generated` v4.1.1, R4 only → `lib/php-fhir/`; Composer dep removed
- [x] IG tarballs → `fhir-packages/` + `SOURCES.md`
- [x] Validator in `docker-compose.yml` (pinned `1.0.84`), IGs mounted at `/igs`
- [x] **CI**: `.github/workflows/fhir-conformance.yml`, manifest-driven
- [x] Migrations: `resource_identity_map`, `referral_tasks`, `outbound_referrals`, `audit_events`
- [x] Repo layout settled; `git init` done, no commits yet
- [x] Pint excludes `lib/`, `tools/`, `fhir-packages/`

---

## Phase 2 — PH Core layer ✅ COMPLETE

- [x] **Constants module** — `app/Fhir/Constants.php`. Canonicals, identifier systems, code
      systems, and the four values that defeat pattern-matching (PSA systems off-namespace,
      PAN/PEN on `nhdr.gov.ph`, eReferral ValueSets under `www.`, the PSOC canonical conflict)
- [x] **Builders**: Patient, Organization, Practitioner, PractitionerRole, ServiceRequest, Task
- [x] **Builders**: Encounter, Condition, Observation, Provenance
- [x] **Bundle assembler** — `app/Fhir/BundleAssembler.php`. Transaction, `urn:uuid` refs,
      conditional `PUT` upserts for identity, `ifNoneExist` for the Task
- [x] **Golden fixture + CI job** — `generated-referral-bundle.json`, manifest-driven
- [x] **Fixture per variant** — referral and telemedicine, both from committed intake inputs

### What the validator caught that PHP did not

Every one of these was serialised happily by php-fhir and rejected by the validator:

1. **PSGC address extensions need `Coding`**, not `CodeableConcept` (Phase 1)
2. **A LOINC-coded vital pulls in the base vital-signs profile**, which requires a UCUM
   `system` and `code` on the Quantity — a bare `unit` string fails
3. **`Provenance.target` is restricted to ServiceRequest** — targeting the Task as well is an error
4. **`ServiceRequest.occurrence[x]` is sliced CLOSED on `occurrenceDateTime`** — an
   `occurrencePeriod` fails, so a telemedicine window is not structurally expressible

Number 4 is the concrete confirmation of the open risk about telemedicine and `ServiceRequest`.

---

## Phase 3 — Flows

- [x] **Flow 3** — outbound referral: native JSON → translate → deliver. Inline delivery; the
      sender retries by resubmitting the same `message_id` (no payload is stored, so the
      gateway cannot rebuild one on its own)
- [x] **Identity resolution** — PhilSys, then PhilHealth, then register. Missing identifiers
      backfill through the conditional PUT; conflicting ones are refused with `409`
- [ ] **Flow 1** — `GET /fhir/Patient` search for HCPN reads
- [ ] **Flow 4** — `GET /fhir/Task` referral status for HCPN reads
- [ ] **Flow 2** — inbound referral: HCPN → translate → write to native ⚠️ *blocked, see below*

Flows 1, 2 and 4 all assume an external HCPN, which v1 does not have. They stay unstarted by
design, not by omission.

---

## Phase 4 — Policy layer

- [x] Audit log on every submission — `audit_events`, including patient identifiers by design
- [x] Search parameter allowlist for intake — strict schema validation, `OperationOutcome` on failure
- [ ] HCPN authentication (mTLS or OAuth2 — undecided)
- [ ] Per-system bearer tokens on the intake and registry routes ⚠️ *not yet wired; do not
      expose these routes outside the local network*
- [ ] Rate limiting on patient search

---

## Blocked — settle with native system owners

Flow 2 cannot be built until these are answered:

1. Which native table/status does an inbound referral write to? Who gets notified?
2. Inbound referral for a patient not yet in our systems
3. Do native systems push to the gateway, or does the gateway poll them?
4. Can they expose a fetchable URL for attachments?
5. Do they store PSGC codes, or only address text?

---

## Order

Phase 0 → 1 → 2 → 3 (flow 3 ✅) → 4.
Flows 1, 2 and 4 wait for an external HCPN and for the blocked items to clear.
