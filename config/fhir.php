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
