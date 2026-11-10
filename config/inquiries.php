<?php

return [
    // Enable only after the public notice, private retention policy and responsible operator are chosen.
    'enabled' => env('CONTACT_INQUIRIES_ENABLED', false),
    'test_order_inquiries_enabled' => env('CONTACT_TEST_ORDER_INQUIRIES_ENABLED', false),
    'privacy_notice' => env('CONTACT_INQUIRIES_PRIVACY_NOTICE'),
    'retention_policy_reference' => env('CONTACT_INQUIRIES_RETENTION_REFERENCE'),
    'operator_user_id' => env('CONTACT_INQUIRIES_OPERATOR_ID'),
    // A bound reviewed transport is also required. Saved inquiries do not imply notification delivery.
    'operator_notifications_enabled' => env('CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED', false),
    // Operator alert mail (App\Domain\Inquiries\Notifications\MailInquiryAlertTransport). Code literals, never environment
    // reads. The transport is admitted in local, testing and staging; in production it binds and sends only with an exact
    // `true` here, which is set only in the same reviewed change that lifts the production pre-launch hold on the flag above.
    'operator_alert_mail_production_admitted' => false,
    // An alert intent older than this many minutes (1 to 10080) is never sent, so enabling alerts cannot release a backlog.
    'operator_alert_max_age_minutes' => 1440,
];
