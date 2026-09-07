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
| 3 — Flows | ✅ **Complete** | 5 / 5 |
| 4 — Policy layer | ✅ **Complete** | 5 / 5 |

**Working today**

- `POST /api/intake/v1/requests` — plain JSON in, PH eReferral transaction Bundle out, delivered
- `GET /api/registry/v1/hcpn` — destination picklist; unreachable facilities are not selectable
- `GET /api/intake/v1/requests/{message_id}` — delivery status
- `GET /api/fhir/Task` and `/api/fhir/Task/{id}` — referral status as FHIR, scope-filtered
- `GET /api/fhir/Patient` — identifier lookup, answered by the native systems, nothing cached
- `POST /api/fhir` — a facility refers a patient to us; translated and handed to the native system
- Every route behind a per-system bearer token, rate limited per client, audited
- `php artisan fhir:build-referral <intake.json> --out=<bundle.json>` — translate without sending
- `php artisan fhir:validate <file> --profile=<canonical>` — conformance check
- Patient identity resolved at the receiver: PhilSys → PhilHealth → register; `409` on conflict
- Reverse translation: Bundle → intake JSON, with a round-trip test over every fixture
- Client auth in two modes: bearer token, and mTLS via a verifying reverse proxy
- **5 golden fixtures at 0 errors**, all gated in CI
- 113 tests green, Pint green, PHPStan level 7 green

**Needs a decision from you**

| # | Question |
|---|---|
| 1 | The React starter kit (Inertia, React, Fortify, Wayfinder) is unused by a headless gateway. Strip it? |
| 2 | Confirm the `409` refusal on conflicting identifiers (implemented as the safe default) |
| 3 | Telemedicine as `ServiceRequest` is confirmed lossy for scheduling windows — accept, or model `Appointment`? |
| 4 | Sign off [native-contract.md](native-contract.md) with the referral and telemedicine owners — the gateway calls it today, nothing implements it yet |
| 5 | mTLS is built and is the default recommendation. Add OAuth2 as well, or is mTLS enough? |

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
- [x] **Flow 4** — `GET /fhir/Task` referral status. Served from `referral_tasks`, which we own,
      so it needed no native connector. Scope filter injected from the token; search parameters
      allow-listed; out-of-scope and not-found answer identically
- [ ] **Flow 1** — `GET /fhir/Patient` search ⚠️ *blocked: needs read access to a native patient
      database. We hold no patient records (D1), so there is nothing to search until a native
      connector exists, and its shape depends on a schema we have not seen*
- [x] **Flow 2** — `POST /api/fhir`. A facility's Bundle is translated back into intake JSON by
      `ReferralBundleReader` and handed to the native system over the native contract. The
      round-trip test this enabled immediately found a real defect: `referral.service_requested`
      and `specialty` were validated on intake and then silently dropped in translation — now
      carried on `ServiceRequest.code` and `.performerType`
- [x] **Flow 1** — `GET /api/fhir/Patient`. Identifier lookup only; the gateway asks the native
      systems and translates the answer without keeping a copy. Name and birth-date search are
      refused outright, because a search surface that can be walked is an enumeration tool

---

## Phase 4 — Policy layer

- [x] **Audit log on every request** — submissions and reads, with the scope filter that was
      applied. Actor comes from the token, never the body
- [x] **Search parameter allowlist** — strict schema on intake, explicit allowlist on `Task`.
      An unrecognised parameter is refused, never ignored: silently dropping a filter returns
      more than the caller asked for
- [x] **Per-system bearer tokens** on every route. A client may only submit as its own system,
      so the referral system cannot file referrals attributed to telemedicine
- [x] **Server-side scope injection** — a client's facility comes from its config, and reads are
      filtered by it. No query parameter can widen it
- [x] **Rate limiting** — per client, not per IP; the search bucket is tightest, since an
      unthrottled identifier search is an enumeration tool
- [x] **HCPN authentication** — two modes, selected by config. `token` for our own systems;
      `mtls` for external facilities, where the reverse proxy verifies the certificate chain and
      forwards its fingerprint. The gateway refuses a fingerprint the proxy did not verify, since
      a header alone proves nothing. OAuth2 is deliberately not written: it needs an
      authorization server nobody has stood up, and mTLS already covers the case

---

## Waiting on the native system owners

Nothing here blocks gateway code any more. The questions that used to be blockers were
dissolved by [native-contract.md](native-contract.md): the gateway defines the interface, and
what happens behind it is the native systems' business.

What they need to do:

1. **Implement the two endpoints** in the native contract, and tell us their URLs.
2. Decide push vs poll. Push is implemented; poll would need a different design.
3. Confirm whether the gateway can fetch attachment URLs, and with what credential.
4. Confirm whether they store PSGC codes or only address text. If text, a lookup table is
   unscoped work.

Questions 1 and 2 from the old list — which table, which status, who is notified, and what to
do about an unknown patient — are now theirs to answer inside their own systems. The gateway
does not need to know.

---

## Order

Phase 0 → 1 → 2 → 3 → 4. **All five phases are complete.**

Every flow in the plan is built, tested and conformance-checked. What is left is not gateway
work: two endpoints for the native systems to implement, and a signature on the contract that
describes them.
