<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (! $app->environment('testing')) { throw new LogicException; }
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 8, JSON_THROW_ON_ERROR);
    if (! is_dir($input['root']) || ! is_dir($input['barrier']) || ! in_array($input['worker'], [0, 1], true)) { throw new LogicException; }
    config(['filesystems.disks.local.root' => $input['root'], 'filesystems.disks.local.serve' => false,
        'filesystems.disks.local.visibility' => 'private']);
    Illuminate\Support\Facades\Storage::forgetDisk('local');
    file_put_contents($input['barrier'].'/ready-'.$input['worker'], (string) getmypid());
    $deadline = microtime(true) + 10;
    while (! is_file($input['barrier'].'/release')) {
        if (microtime(true) >= $deadline) { throw new RuntimeException; }
        usleep(10000); clearstatcache();
    }
    if (($input['action'] ?? null) === 'short_write') {
        if (! function_exists('posix_setrlimit') || ! function_exists('pcntl_signal')
            || ! pcntl_signal(SIGXFSZ, SIG_IGN) || ! posix_setrlimit(POSIX_RLIMIT_FSIZE, 16, 16)) { throw new RuntimeException; }
    }
    try {
        $bytes = base64_decode($input['bytes'], true);
        $pdf = new App\Domain\Contracts\RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), 1,
            hash('sha256', 'Synthetic storage-only envelope'), hash('sha256', 'synthetic-storage-profile'));
        $record = (new App\Domain\Contracts\ContractFiles)->store($input['request'], $input['claim'], $pdf);
        $result = ['outcome' => 'stored', 'record' => $record];
    } catch (App\Domain\Contracts\ContractIssuanceException $error) { $result = ['outcome' => $error->reason]; }
    echo json_encode($result + ['pid' => getmypid()], JSON_THROW_ON_ERROR); exit(0);
} catch (Throwable) { exit(1); }
