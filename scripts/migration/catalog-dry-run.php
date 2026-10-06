<?php

declare(strict_types=1);
use App\Domain\Migration\DryRunFiles;

// Diagnostics deliberately contain no paths, source values or exception messages.
error_reporting(0);
ini_set('display_errors', '0');

try {
    $options = [];
    for ($position = 1; $position < $argc; $position += 2) {
        $name = $argv[$position];
        $value = $argv[$position + 1] ?? null;
        if (! in_array($name, ['--manifest', '--target', '--output', '--limit'], true)
            || array_key_exists($name, $options) || $value === null || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException;
        }
        $options[$name] = $value;
    }
    foreach (['--manifest', '--target', '--output'] as $required) {
        if (! isset($options[$required])) {
            throw new InvalidArgumentException;
        }
    }
    $limit = $options['--limit'] ?? '1000';
    if (! preg_match('/\A[1-9][0-9]{0,3}\z/D', $limit) || (int) $limit > 1000) {
        throw new InvalidArgumentException;
    }

    require dirname(__DIR__, 2).'/vendor/autoload.php';

    $result = (new DryRunFiles)->run($options['--manifest'], $options['--target'], $options['--output'], (int) $limit);
    $status = ! $result['complete'] ? 2 : ($result['counts']['conflict'] > 0 ? 3 : 0);
    $summary = [
        'code' => match ($status) {
            0 => 'dry_run_complete',
            2 => 'dry_run_partial',
            3 => 'dry_run_conflicts',
        },
        'processed' => $result['processed'],
        'total' => $result['total'],
        'counts' => $result['counts'],
        'production_writes' => 0,
        'input_manifest_sha256' => $result['input_manifest_sha256'],
        'target_snapshot_sha256' => $result['target_snapshot_sha256'],
        'plan_sha256' => $result['plan_sha256'],
    ];
} catch (Throwable) {
    $status = 1;
    $summary = ['code' => 'dry_run_invalid'];
}

fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
exit($status);
