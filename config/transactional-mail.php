<?php

return [
    // Lane CX (FP-075): queued order-ready email for the private test order-ready intents. Default-off. No sender or
    // provider is chosen (U-07; owner input #9), so nothing here can send until an operator sets every value below and the
    // existing email preflight (scripts/ops/email-operations-preflight.py) has passed on the declared facts.
    'enabled' => env('VASEY_TRANSACTIONAL_MAIL_ENABLED', false),

    // The name of an SMTP mailer in config/mail.php; its host, port and scheme must match the declared planned facts.
    'mailer' => env('VASEY_TRANSACTIONAL_MAIL_MAILER'),

    // The From address; it must equal the declared planned sender.
    'sender' => env('VASEY_TRANSACTIONAL_MAIL_SENDER'),

    // Absolute paths and SHA-256 values of the operator facts file and of the preflight's JSON report on that file.
    'preflight' => [
        'facts_path' => env('VASEY_TRANSACTIONAL_MAIL_FACTS_PATH'),
        'facts_sha256' => env('VASEY_TRANSACTIONAL_MAIL_FACTS_SHA256'),
        'receipt_path' => env('VASEY_TRANSACTIONAL_MAIL_PREFLIGHT_RECEIPT_PATH'),
        'receipt_sha256' => env('VASEY_TRANSACTIONAL_MAIL_PREFLIGHT_RECEIPT_SHA256'),
    ],
];
