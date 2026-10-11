<?php

declare(strict_types=1);
use App\Domain\Migration\BeatStars\BeatStarsDryRunFiles;

// BeatStars export dry run: reads one CSV export and one mapping file, writes a report into one
// private directory, never boots Laravel and therefore never opens a database. Diagnostics are
// reason codes only; no path, cell value or exception message reaches stdout or stderr.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

try {
    $options = [];
    for ($position = 1; $position < $argc; $position += 2) {
        $name = $argv[$position];
        $value = $argv[$position + 1] ?? null;
        if (! in_array($name, ['--export', '--mapping', '--output'], true) || array_key_exists($name, $options)
            || $value === null || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException('usage');
        }
        $options[$name] = $value;
    }
    foreach (['--export', '--mapping', '--output'] as $required) {
        if (! isset($options[$required])) {
            throw new InvalidArgumentException('usage');
        }
    }

    require dirname(__DIR__, 2).'/vendor/autoload.php';

    $outcome = (new BeatStarsDryRunFiles)->run($options['--export'], $options['--mapping'], $options['--output']);
    $counts = $outcome['result']['counts'];
    $status = $counts['findings'] === 0 ? 0 : 3;
    $summary = ['code' => $status === 0 ? 'dry_run_clean' : 'dry_run_findings', 'rows' => $counts['rows'],
        'normalized' => $counts['normalized'], 'withheld' => $counts['withheld'], 'findings' => $counts['findings'],
        'snapshot' => $outcome['result']['snapshot_sha256'] === null ? 'none' : 'catalog.json',
        'written' => $outcome['written'], 'unchanged' => $outcome['unchanged'], 'database_writes' => 0];
} catch (Throwable $error) {
    $status = 1;
    $reason = $error instanceof InvalidArgumentException && preg_match('/\A[a-z0-9_]{1,64}\z/D', $error->getMessage()) === 1
        ? $error->getMessage() : 'internal_error';
    $summary = ['code' => 'dry_run_refused', 'reason' => $reason, 'database_writes' => 0];
}

fwrite(STDOUT, json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
exit($status);
