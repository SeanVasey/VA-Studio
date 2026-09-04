<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'directory' => 'livewire-tmp',
        'rules' => ['required', 'file', 'max:204800'],
        'middleware' => ['web', 'auth', 'can:administer-catalog', 'throttle:20,1'],
        // Private masters must never receive a temporary public preview URL.
        'preview_mimes' => [],
        'max_upload_time' => 15,
        'cleanup' => true,
    ],
];
