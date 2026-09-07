<?php

declare(strict_types=1);

return [
    /*
     * HTTP endpoint of the containerised HL7 FHIR validator.
     * Started with: docker compose up -d validator
     */
    'validator_url' => env('FHIR_VALIDATOR_URL', 'http://localhost:3500'),

    /*
     * Namespace for identifiers that only mean something inside our own estate: the sender's
     * message id, and any `local` patient identifier (an MRN) forwarded from a native system.
     * A receiving facility cannot resolve these, but round-tripping them lets both sides trace
     * a referral back to the record it came from.
     */
    'message_identifier_system' => env('FHIR_MESSAGE_ID_SYSTEM', 'https://gateway.local/fhir/Identifier/message-id'),

    'local_identifier_system' => env('FHIR_LOCAL_ID_SYSTEM', 'https://gateway.local/fhir/Identifier/local-mrn'),

    /*
     * The systems allowed to talk to the gateway.
     *
     * `facility` is the client's DOH NHFR code and is the scope filter applied to every read:
     * a client sees referrals its own facility sent or received, and nothing else. It is taken
     * from here, never from the request, so a caller cannot widen their own scope.
     *
     * `systems` lists the values this client may put in `source.system`, which stops the
     * referral system from submitting referrals attributed to telemedicine.
     */
    'clients' => [
        'referral' => [
            'token' => env('GATEWAY_TOKEN_REFERRAL'),
            'facility' => env('GATEWAY_FACILITY_REFERRAL'),
            'systems' => ['referral'],
        ],
        'telemedicine' => [
            'token' => env('GATEWAY_TOKEN_TELEMEDICINE'),
            'facility' => env('GATEWAY_FACILITY_TELEMEDICINE'),
            'systems' => ['telemedicine'],
        ],
    ],

    /*
     * How a client proves who it is.
     *
     * `token` is a shared bearer secret and is what our own systems use. `mtls` is for external
     * facilities: TLS terminates at the reverse proxy, which verifies the client certificate
     * against our CA and forwards its fingerprint. The gateway trusts the *verify* header only
     * when the proxy says the chain checked out, and matches the fingerprint to a client.
     *
     * Both are implemented; enabling mtls is a deployment decision, not a code change. OAuth2
     * would be a third mode and is not written — it needs an authorization server that does not
     * exist yet, and choosing it over mTLS is the open question in the plan.
     */
    'client_auth' => [
        'modes' => array_filter(explode(',', (string) env('GATEWAY_AUTH_MODES', 'token'))),

        // Set by the reverse proxy. Never accept these from an untrusted hop.
        'mtls_verify_header' => env('GATEWAY_MTLS_VERIFY_HEADER', 'X-Client-Verify'),
        'mtls_fingerprint_header' => env('GATEWAY_MTLS_FINGERPRINT_HEADER', 'X-Client-Fingerprint'),
    ],

    /*
     * Per-minute request ceilings. Patient-facing search is the tightest: an unthrottled
     * identifier search is an enumeration tool.
     */
    'rate_limits' => [
        'intake' => (int) env('FHIR_RATE_LIMIT_INTAKE', 60),
        'read' => (int) env('FHIR_RATE_LIMIT_READ', 120),
        'search' => (int) env('FHIR_RATE_LIMIT_SEARCH', 30),
    ],

    /*
     * Our own systems, as delivery destinations. Seeded into the destination registry rather
     * than read at request time — the registry is the single place routing is resolved.
     */
    'internal' => [
        'telemedicine_endpoint' => env('TELEMEDICINE_FHIR_URL', 'http://localhost:8081/fhir'),

        // The native contract endpoints each system exposes (docs/native-contract.md).
        // Null until the system owners implement them; the gateway refuses rather than
        // pretending it can deliver.
        'referral_facility' => env('GATEWAY_FACILITY_REFERRAL', 'DOH000000000000002'),
        'referral_inbound_url' => env('REFERRAL_INBOUND_URL'),
        'referral_patient_search_url' => env('REFERRAL_PATIENT_SEARCH_URL'),
        'telemedicine_inbound_url' => env('TELEMEDICINE_INBOUND_URL'),
        'telemedicine_patient_search_url' => env('TELEMEDICINE_PATIENT_SEARCH_URL'),
    ],

    /*
     * Outbound delivery limits.
     */
    'delivery' => [
        'timeout' => (int) env('FHIR_DELIVERY_TIMEOUT', 30),
        'max_attempts' => (int) env('FHIR_DELIVERY_MAX_ATTEMPTS', 5),
        // Backoff in seconds per attempt; the last value repeats once attempts run past the end.
        'backoff' => [60, 300, 900, 3600],
    ],
];
