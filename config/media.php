<?php

return [
    // No scanner bypass or synthetic seller tag exists outside the test suite.
    'profile_version' => 'wav-preview-v1',
    'ffmpeg' => env('MEDIA_FFMPEG', '/usr/bin/ffmpeg'),
    'ffprobe' => env('MEDIA_FFPROBE', '/usr/bin/ffprobe'),
    'prlimit' => env('MEDIA_PRLIMIT', '/usr/bin/prlimit'),
    'max_signature_age_seconds' => 172800,
    'clamscan' => env('MEDIA_CLAMSCAN', '/usr/bin/clamscan'),
    'tag_path' => env('MEDIA_TAG_PATH'), // Relative to the private local disk; approved WAV only.
    'tag_sha256' => env('MEDIA_TAG_SHA256'),
    'tag_interval_seconds' => 30,
    'max_source_bytes' => 536870912,
    // Lower these for a smaller worker; StemsArchive enforces these hard ceilings.
    'stems' => ['max_entries' => 128, 'max_member_bytes' => 134217728, 'max_total_bytes' => 536870912, 'max_ratio' => 100, 'max_seconds' => 360],
    'max_artwork_bytes' => 20971520,
    'max_duration_seconds' => 1200,
    'max_tag_duration_seconds' => 15,
    'max_artwork_dimension' => 6000,
    'waveform_points' => 200,
    'process_timeout_seconds' => 120,
    'cpu_seconds' => 90,
    'memory_bytes' => 2147483648,
    'max_output_bytes' => 1073741824,
    'max_process_log_bytes' => 262144,
    // Exceeds the job timeout; a crashed worker can be retried after this lease.
    'claim_lease_seconds' => 960,
    'queue' => 'media',
];
