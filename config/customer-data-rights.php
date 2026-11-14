<?php

return [
    // Lane CX (FP-082): customer data export and deletion requests. Default-off; also requires the existing customer
    // access policy. Every customer endpoint answers 404 while this is false.
    'enabled' => env('VASEY_CUSTOMER_DATA_RIGHTS_ENABLED', false),

    // A prepared export is downloadable once, by the same signed-in customer, for this many minutes (5 to 60).
    'export_ttl_minutes' => 15,

    // Exports larger than this many bytes of JSON refuse (64 KiB to 4 MiB); the customer contacts support@vasey.audio.
    'export_max_bytes' => 1048576,

    // Retention schedule (U-13, FP-076). Sean approved the periods (PRIVACY section 7), but they are set per host and never
    // in Git, so every value reads its environment variable with no default; while any is unset, staff approval of a
    // deletion request and the retention report refuse. Each period is a whole number from 1 to 100 of the unit its key
    // names, and the reference names the approved schedule.
    'retention' => [
        'policy_reference' => env('VASEY_CUSTOMER_DATA_RETENTION_POLICY_REFERENCE'),
        // Orders, payment evidence, contracts, license grants and delivery records: the minimum period PRIVACY names. The
        // report holds every such record with its order; the value is recorded with each deletion approval.
        'financial_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_FINANCIAL_YEARS'),
        // Marketing consent and suppression ledgers.
        'consent_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_CONSENT_YEARS'),
        // Contact and order inquiries with their replies and attachments, and order-ready notices.
        'correspondence_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_CORRESPONDENCE_YEARS'),
        // Identity and purchase challenges, verification records and password reset tokens (PRIVACY: 12 months).
        'security_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_SECURITY_YEARS'),
        // Privacy request records, their events and export metadata (PRIVACY: 7 years after the request closes).
        'evidence_records_years' => env('VASEY_CUSTOMER_DATA_RETENTION_EVIDENCE_YEARS'),
        // Quotes and checkout reviews that did not become an order (PRIVACY: 30 days).
        'abandoned_checkout_days' => env('VASEY_CUSTOMER_DATA_RETENTION_ABANDONED_CHECKOUT_DAYS'),
        // Framework job queue and failed-job stores (PRIVACY application error log row: 14 days).
        'operational_records_days' => env('VASEY_CUSTOMER_DATA_RETENTION_OPERATIONAL_DAYS'),
    ],
];
