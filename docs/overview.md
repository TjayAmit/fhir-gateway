# PH FHIR Gateway — Purpose and Binding Decisions

**Status: settled. These decisions are to be followed.**
Anything that contradicts this document is out of date. Supersedes all earlier drafts (2026-09-07).

Companion context: [PH Core IG](context/ph-core-ig.md) · [PH eReferral IG](context/ph-ereferral-ig.md)

---

## 1. Purpose

A **bidirectional translation facade** between our native systems and the HCPN.

We already run two production systems, each with its own database. Those systems are and remain the
**system of record**. The HCPN needs to search patients and exchange referrals with us using
FHIR R4 conformant to **PH Core** and **PH eReferral**.

The gateway exists to make that possible **without changing the native systems and without copying
their data anywhere.**

It does three things:

1. **Translates** — native schemas ↔ PH Core / eReferral FHIR R4, in both directions
2. **Enforces policy** — authentication, scoping, rate limiting, audit on every HCPN request
3. **Tracks referral workflow** — Task state and the ID correlation between FHIR ids and native primary keys

It is **not** a FHIR repository, not a clinical system, and not a source of truth for anything clinical.

---

## 2. The four flows

```
        HCPN                    Gateway                  Native systems
                            (translate + policy)

1.  search patient   ──►  GET /fhir/Patient   ──►  query native DB
                     ◄──  FHIR Patient        ◄──  translate

2.  refer to us      ──►  POST ServiceRequest ──►  write into native system
                     ◄──  201 + Task          ◄──

3.                        we refer out        ◄──  native system
                     ◄──  send FHIR Bundle    ◄──  translate

4.  referral status  ──►  GET /fhir/Task      ──►  read native state
                     ◄──  FHIR Task           ◄──
```

Flow 2 is the hardest part of the build. Everything else reads and translates; flow 2 writes into a
system that was never designed to receive external referrals.

---

## 3. Binding decisions

### D1 — Facade, not repository

The gateway **MUST NOT** persist clinical or demographic data. It queries the native systems live and
translates on the fly.

*Why:* the native systems are the system of record. A second copy creates sync, staleness, and a second
PHI exposure for no benefit.

### D2 — No HAPI FHIR server

**We do not run HAPI FHIR, or any FHIR server, in this architecture.**

This was seriously considered and rejected. Recording the reasoning so it is not re-litigated.

**What HAPI would have given us:** full FHIR search, `_include`, pagination, history, a generated
CapabilityStatement, and IG validation on write — all free, from the reference implementation.

**Why we rejected it:**

1. **It duplicates clinical data.** Even scoped to referrals only, it holds a copy of records that already
   live in our native systems. Sync and staleness become permanent problems.
2. **Patient search must be live.** Demographics change. A stored projection returns stale answers to the HCPN.
3. **Inbound referrals must reach clinicians.** A referral sitting in a separate FHIR store is invisible to
   staff working in the native systems. It has to be written into the systems they actually use — which
   means the native write path is required regardless, making the FHIR store redundant.
4. **Operational cost with no offsetting gain.** It adds a Java runtime and a second datastore to production,
   given points 1–3 buy us nothing.

**What we accept as the cost:**

- We implement a small FHIR search surface by hand (see D6)
- Translation runs on every read rather than once on write
- If a native system is down, the HCPN gets nothing for that flow

**Revisit this decision if** — and only if — one of these becomes true:

- Retaining the referral **snapshot** (the clinical picture as sent, not as it stands today) becomes a legal
  or clinical requirement. A referral is a point-in-time document, and if we must prove what was sent, a
  store becomes correct.
- HCPN query volume or latency makes live translation untenable.

### D3 — PHP

Implementation language is **PHP**, using `dcarbone/php-fhir-generated` (R4) for typed models and
serialization.

*Why:* this is a translation gateway — the code is overwhelmingly field-to-field mapping, and team
fluency dominates library quality. No FHIR library in any language ships PH Core classes, so the
library choice buys less than it appears to.

**The generated classes MUST be vendored** into this repo. The upstream package has a single maintainer;
vendoring makes abandonment a non-event.

### D4 — Java only in CI

There is **no Java in production.**

The HL7 FHIR Validator (Java, containerised) runs in **CI only**, against golden output fixtures.
It is the only component anywhere in our stack that knows PH Core exists — nothing else validates profiles.

- **Outbound** (what we emit): validated **strictly** in CI. Every referral variant we can produce gets a
  golden fixture, validated on every commit. A thin fixture suite means silent conformance drift.
- **Inbound** (what HCPN sends): validated **leniently**. Log failures, do not reject on warnings.

### D5 — Operational storage only

The gateway keeps a small database (standard Laravel/Postgres) holding **operational metadata only**:

| Store | Do not store |
|---|---|
| Referral / Task workflow state | Patient records |
| **ID correlation: FHIR id ↔ native primary key** | Clinical data |
| Audit log of every HCPN request | Duplicated demographics |
| Outbox and retry state for outbound sends | |

The **ID correlation table is essential and permanent.** HCPN addresses resources by FHIR id; our systems
use their own keys. That mapping must be stable for the life of the system.

### D6 — Minimal search surface

We hand-implement FHIR search, so we implement as little as possible. Allowlist only:

```
GET /fhir/Patient?identifier=<philsys|philhealth>|<value>
GET /fhir/Patient?family=..&birthdate=..     (only if HCPN genuinely requires it)
GET /fhir/ServiceRequest?_id=..
GET /fhir/Task?focus=..
```

Everything else returns an `OperationOutcome`. Prefer identifier lookup over name search — it requires the
HCPN to already know who they are looking for rather than browsing our patient database.

Patient search is our **largest exposure surface**. It MUST be rate limited, scope filtered server-side, and
every request logged.

### D7 — Never trust the incoming query

On every HCPN read, the gateway **MUST** inject a server-side scope filter the caller cannot remove, before
the query reaches any native system.

A pass-through proxy exposes every patient we hold. This is the single most important control on the read path.

---

## 4. PH Core conformance is ours to write

No library provides it. PH Core alignment comes from exactly three places:

1. **A constants module** — canonical URLs, identifier system URIs, required-binding codes.
   This is `docs/context/ph-core-ig.md` turned into code.
2. **Builders per profile** — stamping `meta.profile`, identifier slices, PSGC address extensions.
3. **The CI validator** — the only automated check that any of it is correct.

Note that validation proves output is **conformant**, not **correct**. Mapping a field to the wrong place
passes validation cleanly. Golden fixtures need human review by someone who knows the referral domain.

---

## 5. Still open

| # | Question |
|---|---|
| 1 | Outbound referrals: do native systems push to the gateway, or does the gateway poll them? |
| 2 | Flow 2 — which native table/status does an inbound referral write to, and who gets notified? |
| 3 | Flow 2 — duplicate submission handling (idempotency) |
| 4 | Flow 2 — inbound referral for a patient not yet in our systems |
| 5 | HCPN authentication: mTLS, OAuth2 client credentials, or both? |
| 6 | Retention and audit obligations under the HCPN data-sharing agreement |
| 7 | Facility directory — how we resolve a receiving facility and its endpoint |
| 8 | Whether name + birthdate patient search is exposed at all |

Items 2–4 are the real build risk. Settle them before writing mapper code.

---

## 6. First steps

1. Round-trip `Bundle-ExampleERefSubmissionBundle` through `php-fhir` R4 and diff — confirm primitive
   extensions (`_birthDate`), `urn:uuid` references, and choice types survive intact
2. Stand up the validator container locally and in CI
3. Write the constants module from `docs/context/`
4. Settle open items 2–4 with the owners of the native systems
