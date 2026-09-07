# Vendored FHIR IG packages

Pinned tarballs used by the validator. Re-download with the URLs below.

| File | Package | Version | Source | Build date |
|---|---|---|---|---|
| `ph-core.tgz` | `fhir.ph.core` | 0.2.0 | https://build.fhir.org/ig/niccoreyes/ph-core/package.tgz | 2026-06-21 |
| `ph-ereferral.tgz` | `fhir.ph.ereferral` | 0.1.0 | https://build.fhir.org/ig/ph-ereferral-organization/ph-ereferral/package.tgz | 2026-06-20 |

Note: `package.tgz` lives at the **IG root**, not under `/en/`.

## Why the niccoreyes build of PH Core

Three builds of `fhir.ph.core` 0.2.0 exist and were compared:

| Build | Date | THO |
|---|---|---|
| `niccoreyes` (CI) — **chosen** | 2026-06-21 | 7.2.0 |
| `UP-Manila-SILab` (CI) | 2026-06-20 | 7.2.0 |
| `fhir.doh.gov.ph/phcore` (published) | 2026-06-02 | 7.1.0 |

Chosen because it is the newest and its HL7 Terminology 7.2.0 matches what eReferral depends on.
The published DOH build is older and pins THO 7.1.0.

`fhir.ph.ereferral` is **not** published at `fhir.doh.gov.ph/pheref` (404) — CI build only.

## Transitive dependencies

Both IGs depend on packages the validator fetches from `packages.fhir.org` on first run
(needs network once, then cached):

- `hl7.fhir.r4.core` 4.0.1
- `hl7.terminology.r4` 7.2.0
- `hl7.fhir.uv.extensions.r4` 5.3.0

eReferral declares `fhir.ph.core: "dev"` — an unresolvable floating ref, so PH Core **must** be
supplied explicitly to the validator from `ph-core.tgz`.
