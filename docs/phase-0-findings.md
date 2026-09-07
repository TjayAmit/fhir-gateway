# Phase 0 — Package Verification Findings

Ran 2026-09-07. Gate from [tasks.md](tasks.md): does `dcarbone/php-fhir-generated` handle PH Core / eReferral JSON cleanly?

## Verdict: **PASS** — proceed with PHP

Two caveats to carry forward, neither a blocker. Both recorded below.

---

## Environment

| | |
|---|---|
| PHP | 8.5.6 (Windows, ZTS VC++ 2022 x64) — package requires `^8.1`, works fine |
| Composer | 2.8.8 |
| WSL | **No PHP installed** — all work done on Windows-side PHP |
| Package | `dcarbone/php-fhir-generated` **v4.1.1** |

**Version mapping correction:** the README's table (v4.0 = R4, v4.3 = R4B, v5.x = R5) is stale. v4.1.1 ships
**all six FHIR versions in one package** under `Versions/{DSTU1,DSTU2,STU3,R4,R4B,R5}`.
R4 reports `FHIR_SEMANTIC_VERSION = v4.0.1` — correct.

API: `ResourceParser::parseJSON(new Version(), $json)`, resources are `JsonSerializable`.

---

## Test 1 — Real eReferral submission bundle

`Bundle-ExampleERefSubmissionBundle` (64KB, transaction, 21 entries) parsed → re-serialized → diffed.

**Result: byte-identical after canonicalisation.** Confirmed by two independent methods
(recursive structural walk, and canonical-sorted MD5 — `7cc97ec06f27065298f54ded2591d25e` both sides).

| Check | Before | After |
|---|---|---|
| `urn:uuid` references | 118 | 118 |
| PH canonical URLs (`fhir.doh.gov.ph`) | 76 | 76 |
| PH identifier systems (PhilSys/PhilHealth) | 34 | 34 |
| `extension[]` entries | 3 | 3 |
| Choice types (`effectiveDateTime`, `occurrenceDateTime`, `valueCoding`, `valueQuantity`) | 26 | 26 |

Parse time 0.08–0.25s for the 64KB bundle. Acceptable.

## Test 2 — Synthetic PH Core Patient

The real bundle contains **zero primitive extensions**, so that gate criterion was untested by Test 1.
Built a synthetic PH Core Patient to cover it.

| Check | Result |
|---|---|
| `_gender` primitive extension | **preserved** |
| `_birthDate` primitive extension | **preserved** |
| `meta.profile` PH Core canonical | preserved |
| PH extensions (indigenous-people, educational-attainment) | preserved |
| Address extension (barangay, PSGC-coded) | preserved |
| Identifier slices (PhilSys + PhilHealth) | preserved |
| `deceasedBoolean`, `multipleBirthInteger` choice types | preserved |

Canonical match after correcting a fault in the fixture (see Caveat A).

---

## Caveat A — Unknown fields are silently dropped

The first synthetic fixture set `Address.province`. That is **not a FHIR R4 element** (R4 has `state` and
`district`; PH Core carries province as an *extension*). The library dropped it — **no error, no warning,
no exception.**

The library was right to reject it. The concern is *how*: a mapper that writes a wrong field name loses
that data silently.

**Consequence:** this is the strongest argument for the CI validator (decision D4). Nothing in the PHP layer
will tell us a field name is wrong. Only the HL7 validator against the IG will.

## Caveat B — Decimal precision is lost

`1.50` serializes as `1.5`. Tested and **not recoverable within the library**:

| Input to `FHIRDecimal::setValue()` | Output |
|---|---|
| `"1.50"` (string) | `1.5` |
| `1.50` (float) | `1.5` |
| `"1.500"` (string) | `1.5` |
| `"0.10"` (string) | `0.1` |

Coerced to float internally regardless of input type.

**Impact:** low but real. Numerically the value is unchanged and the HL7 validator will not flag it — both
are valid decimals. But FHIR treats decimal precision as significant: `1.50 mmol/L` asserts three
significant figures, `1.5` asserts two. Matters for quantitative lab results, not for vitals.

**Options if it ever matters:** post-serialization string fix-up on specific fields, or patch the vendored
`FHIRDecimalPrimitive` to retain the raw string. Since we vendor the classes anyway (D3), patching is viable.

**For now: accept and document.** Revisit if lab results with significant-figure requirements enter scope.

## Caveat C — Key order changes

`resourceType` moves from first to last; sibling key order shifts. Semantically irrelevant in JSON.

**Consequence:** golden fixtures must be compared **canonically** (recursive key sort), never byte-wise.
The harness in `tools/roundtrip/` already does this.

---

## Vendoring note

The full package is **245 MB / 11,396 PHP files** — all six FHIR versions.

R4 alone is **1,128 files**. When vendoring (D3), strip to `Versions/R4` plus the shared
`Encoding/`, `Types/`, `Validation/`, `Client/` roots. Do not commit 245 MB.

---

## Artifacts

`tools/roundtrip/` — harness kept, it becomes the basis of the CI golden-fixture check:

| File | Purpose |
|---|---|
| `roundtrip.php` | Full diff + targeted checks + canonical hash against the real bundle |
| `diff2.php` | Minimal structural differ, prints exact divergent paths |
| `test2.php` | Primitive-extension and edge-case checks |
| `synthetic.json` | PH Core Patient fixture with primitive extensions |
| `submission-bundle.json` | The real eReferral fixture |

## Next

Phase 1 — Laravel project, vendor R4 only, IG tarballs, validator container.
