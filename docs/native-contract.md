# Native System Contract (v0.1 — DRAFT, needs sign-off from the system owners)

**Status: implemented gateway-side, unimplemented native-side.** The gateway calls these two
endpoints today. Nothing works end to end until the referral and telemedicine systems expose
them.

---

## Why this exists

The intake contract settled how our systems *send* to the gateway. This settles how the gateway
*reaches back*, and it is deliberately the mirror image.

The alternative was for the gateway to read native tables directly. That was rejected twice
over: it would need a schema nobody has published, and it would couple the gateway to the
internals of two systems that are free to change them. Defining an interface is the gateway's
job; guessing at a schema is not.

So the shape is the same as the intake contract, in reverse — **plain JSON, never FHIR.** A
native system implementing this never parses a Bundle, a profile or a canonical URL.

Two endpoints. Both are yours to implement; the gateway is the client.

---

## 1. Receive a referral — `POST {inbound_url}`

Called when another facility refers a patient *to* us.

```
POST https://referral.internal/gateway/referrals
Authorization: Bearer <token you issue to the gateway>
Content-Type: application/json
Idempotency-Key: <message_id>
```

The body is **exactly the intake contract shape** (see [intake-contract.md](intake-contract.md)),
because it is the same referral, travelling the other way:

```json
{
  "message_id": "0f1a5c8e-2b7d-4a91-9f3e-6c2d8b4a1e07",
  "sent_at": "2026-09-07T14:32:10+08:00",
  "request_type": "referral",
  "source": { "record_id": "...", "facility_code": "513", "facility_name": "...", "practitioner": { } },
  "destination": { "hcpn_id": "...", "nhfr_code": "3056" },
  "patient": { "identifiers": [ ], "family_name": "...", "birth_date": "...", "sex": "..." },
  "clinical": { "reason_text": "...", "diagnoses": [ ], "vitals": [ ] },
  "referral": { "category": "emergency", "service_type": "procedure", "priority": "urgent" }
}
```

`source` is now the *sending* facility, which is someone else. `destination.nhfr_code` is ours.

### What you must return

| Code | Meaning |
|---|---|
| `201` / `200` | Accepted. Return `{"native_id": "<your record id>"}` so the gateway can quote it back on status queries. |
| `409` | You already have this `message_id`. Return the original `native_id`; do not create a second referral. |
| `422` | You cannot accept it. Return `{"error": "..."}`; the gateway relays this to the sender. |

### What you decide, not us

These were the open questions that used to block this flow. They are yours, and this contract
is what makes them yours:

- which table the referral lands in, and in what status
- who gets notified, and how
- what happens when the patient is not yet in your records — register them, or hold the referral
  for a clerk. The payload carries both national identifiers so you can match first.

The gateway does not need to know any of it. It needs a `201` and an id.

---

## 2. Patient lookup — `GET {patient_search_url}`

Called when a facility asks the gateway whether we know a patient.

```
GET https://referral.internal/gateway/patients?identifier=http://philsys.gov.ph/fhir/Identifier/philsys-id|7731-0812-4491-0326
Authorization: Bearer <token you issue to the gateway>
```

`identifier` is `system|value`, the two national systems only. Return **the intake contract's
`patient` block**, one per match:

```json
{
  "data": [
    {
      "identifiers": [
        { "type": "philsys", "value": "7731-0812-4491-0326" },
        { "type": "philhealth", "value": "78-658064775-3" }
      ],
      "family_name": "Reyes",
      "given_names": ["Ana", "Luisa"],
      "birth_date": "1988-03-12",
      "sex": "female",
      "mobile": "+63-919-876-5432",
      "address": { "barangay_psgc": "0600407013", "city_psgc": "0600407000", "postal_code": "5600" },
      "native_id": "MRN-0099123"
    }
  ]
}
```

Return `{"data": []}` when you have no match — not a `404`.

**Return only what the identifier matched.** Do not return a patient list, do not support
wildcard or name search on this endpoint. The gateway is answering "do you know *this* person",
and a search surface that can be walked is a patient-enumeration tool.

The gateway translates each entry into a PH Core Patient and returns a FHIR searchset. It keeps
none of it — no caching, no copy. That is the whole point of the facade.

---

## Registration

Both URLs live on the destination registry row for your system, alongside the outbound endpoint
that already exists:

| Column | Meaning |
|---|---|
| `endpoint_url` | Where the gateway sends **FHIR** to you (existing; for FHIR-capable systems) |
| `inbound_url` | Where the gateway sends a **received referral** as intake JSON |
| `patient_search_url` | Where the gateway asks whether you know a patient |
| `auth_credential_key` | Config key for the token you issue us. The secret never enters the database. |

A row with no `inbound_url` cannot be handed a referral, and the gateway refuses the inbound
Bundle rather than accepting one it cannot deliver — the same rule the outbound direction uses
for facilities with no endpoint.

---

## Open for the system owners

1. Can you expose these two endpoints, and on what timeline?
2. Do you want the referral pushed as above, or would you rather poll the gateway for pending
   referrals? Push is implemented; poll is not, and would need a different design.
3. Does patient lookup need to cover inactive or merged records, and how should you signal that?
4. Attachments are currently referenced by URL and not fetched. Can the gateway reach your
   attachment URLs, and with what credential?
