<?php

return [
    // Lane CX (FP-082): customer data export and deletion requests. Default-off; also requires the existing customer
    // access policy. Every customer endpoint answers 404 while this is false.
    'enabled' => env('VASEY_CUSTOMER_DATA_RIGHTS_ENABLED', false),

    // A prepared export is downloadable once, by the same signed-in customer, for this many minutes (5 to 60).
    'export_ttl_minutes' => 15,

    // Exports larger than this many bytes of JSON refuse (64 KiB to 4 MiB); the customer contacts support@vasey.audio.
    'export_max_bytes' => 1048576,

    // Retention schedule (U-13, FP-076). Sean has not approved one, so every value is unset and staff approval of a
    // deletion request refuses. Values are whole years from 1 to 100 and a reference to the approved schedule.
    'retention' => [
        'policy_reference' => env('VASEY_CUSTOMER_DATA_RETENTION_POLICY_REFERENCE'),
        // Orders, payment evidence, contracts, license grants and delivery records.
        'financial_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_FINANCIAL_YEARS'),
        // Marketing consent and suppression ledgers.
        'consent_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_CONSENT_YEARS'),
        // Contact and order inquiries with their replies and attachments.
        'correspondence_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_CORRESPONDENCE_YEARS'),
        // Audit events, identity challenges and download authorizations.
        'security_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_SECURITY_YEARS'),
    ],
];
