<?php

/*
 * Staff reporting and operations views (lane REP: FP-028, FP-089, FP-090). Read-only; the only writes are append-only
 * audit rows for exports. The scheduler heartbeat and backup evidence the dashboard reads are written elsewhere
 * (App\Domain\Operations\OperationalSignals).
 *
 * The operational dashboard and the sales report are on for staff with the admin panel's authority and MFA. The customer
 * segmentation mechanism is default-off: which customers may be segmented, for which purpose and with which processors
 * is owner policy (U-13). No contact field is ever read by the export.
 */
return [
    'sales' => [
        // Longest reportable period and the longest period that may be bucketed by day.
        'max_days' => 400,
        'day_buckets_max_days' => 92,
        // Above these counts the page says the figure is unavailable for the period instead of truncating silently.
        'tier_order_cap' => 5000,
        'refund_case_cap' => 200,
        'export_row_cap' => 20000,
    ],
    'customer_segments' => [
        'enabled' => env('REPORTING_CUSTOMER_SEGMENTS_ENABLED', false),
        'export_enabled' => env('REPORTING_CUSTOMER_EXPORT_ENABLED', false),
        // A marketing segment additionally needs this flag; it still admits only verified granted consent.
        'marketing_segment_enabled' => env('REPORTING_MARKETING_SEGMENT_ENABLED', false),
        'account_cap' => 20000,
        'export_row_cap' => 10000,
    ],
    'health' => [
        'queue_age_attention_seconds' => 300,
        'quarantine_age_attention_seconds' => 86400,
        'queued_media_age_attention_seconds' => 900,
        'receipt_age_attention_seconds' => 900,
        'inquiry_age_attention_seconds' => 172800,
        // Operational signals (OperationalSignals). Not secrets; an operator may tune them per host. A value below 1 makes the
        // metric Unavailable (misconfigured) rather than permanently OK or permanently Needs attention.
        'scheduler_heartbeat_stale_seconds' => (int) env('REPORTING_SCHEDULER_HEARTBEAT_STALE_SECONDS', 300),
        'backup_stale_hours' => (int) env('REPORTING_BACKUP_STALE_HOURS', 26),
        'restore_drill_stale_days' => (int) env('REPORTING_RESTORE_DRILL_STALE_DAYS', 35),
    ],
    // Scheduler dead-man's switch (FP-028; App\Domain\Operations\SchedulerWatch). Default-off: no recipient, nothing is sent.
    // The recipient is a plain lowercase address; sending also needs the transactional-mail configuration to admit it.
    'scheduler_alert' => [
        'recipient' => env('REPORTING_SCHEDULER_ALERT_TO'),
        // Null: storage/app/private/ops-state/scheduler-watch.json. Not an env read; tests set an absolute path.
        'state_path' => null,
    ],
];
