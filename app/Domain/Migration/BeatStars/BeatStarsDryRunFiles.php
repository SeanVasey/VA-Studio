<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

use InvalidArgumentException;

/**
 * Private file boundary for the dry run: read two inputs, write at most three outputs, nothing else.
 *
 * The export must be a caller-owned, owner-only, single-link file outside this checkout; only a
 * mapping declaring a synthetic fixture may read one from inside it.
 *
 * The output directory must be absolute, symlink-free, owned by the caller, mode 0700, outside
 * this checkout, and hold nothing but earlier outputs of this tool. Existing identical outputs are
 * left in place; different ones are refused, so a report is never silently replaced.
 */
final class BeatStarsDryRunFiles
{
    public const REPORT_JSON = 'beatstars-dry-run.json';

    public const REPORT_MARKDOWN = 'beatstars-dry-run.md';

    public const SNAPSHOT = 'catalog.json';

    private const OUTPUTS = [self::REPORT_JSON, self::REPORT_MARKDOWN, self::SNAPSHOT];

    /**
     * @return array{result: array, written: list<string>, unchanged: list<string>}
     */
    public function run(string $exportPath, string $mappingPath, string $outputDirectory): array
    {
        $mapping = $this->input($mappingPath, BeatStarsExportMapping::MAX_BYTES);
        $export = $this->export($exportPath, $mapping);
        $this->directory($outputDirectory);
        $result = (new BeatStarsExportNormalizer)->normalize($export, basename($exportPath), $mapping);
        $report = new BeatStarsDryRunReport;
        $outputs = [self::REPORT_JSON => $report->json($result), self::REPORT_MARKDOWN => $report->markdown($result)];
        $snapshot = $report->snapshot($result);
        if ($snapshot !== null) {
            $outputs[self::SNAPSHOT] = $snapshot;
        }
        // Decide every file before writing any file, so a refusal leaves the directory untouched.
        $written = [];
        $unchanged = [];
        foreach ($outputs as $name => $bytes) {
            $path = $outputDirectory.'/'.$name;
            if ($this->exists($path)) {
                $existing = $this->path($path);
                $this->require(($existing['mode'] & 0170000) === 0100000 && $existing['size'] === strlen($bytes)
                    && $this->input($path, strlen($bytes)) === $bytes, 'output_differs');
                $unchanged[] = $name;
            } else {
                $written[] = $name;
            }
        }
        if ($snapshot === null) {
            $this->require(! $this->exists($outputDirectory.'/'.self::SNAPSHOT), 'output_differs');
        }
        $this->directory($outputDirectory);
        foreach ($written as $name) {
            $this->write($outputDirectory.'/'.$name, $outputs[$name]);
        }

        return ['result' => $result, 'written' => $written, 'unchanged' => $unchanged];
    }

    /**
     * A real export is private source material: it must live outside this checkout as a caller-owned,
     * owner-only file with a single link. Only a mapping that declares a synthetic fixture may read
     * an export from inside the checkout.
     */
    private function export(string $path, string $mapping): string
    {
        $bytes = $this->input($path, BeatStarsExportNormalizer::MAX_EXPORT_BYTES);
        $repository = dirname(__DIR__, 4);
        if ($path === $repository || str_starts_with($path, $repository.'/')) {
            $this->require((new BeatStarsExportMapping)->decode($mapping)['acquisition_method'] === 'synthetic_fixture',
                'input_inside_repository');
        } else {
            $stat = $this->path($path);
            $this->require(function_exists('posix_geteuid') && $stat['uid'] === posix_geteuid()
                && ($stat['mode'] & 0077) === 0 && $stat['nlink'] === 1, 'input_not_private');
        }

        return $bytes;
    }

    private function input(string $path, int $maximum): string
    {
        $stat = $this->path($path);
        $this->require(($stat['mode'] & 0170000) === 0100000 && $stat['size'] > 0 && $stat['size'] <= $maximum, 'input_file');
        $bytes = @file_get_contents($path, false, null, 0, $maximum + 1);
        $this->require(is_string($bytes) && strlen($bytes) === $stat['size'], 'input_file');

        return $bytes;
    }

    private function directory(string $path): void
    {
        $stat = $this->path($path);
        $this->require(($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === 0700
            && function_exists('posix_geteuid') && $stat['uid'] === posix_geteuid(), 'output_directory');
        $repository = dirname(__DIR__, 4);
        $this->require($path !== $repository && ! str_starts_with($path, $repository.'/'), 'output_inside_repository');
        $entries = @scandir($path);
        $this->require($entries !== false, 'output_directory');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->require(in_array($entry, self::OUTPUTS, true), 'output_directory_not_empty');
            $entryStat = $this->path($path.'/'.$entry);
            $this->require(($entryStat['mode'] & 0170000) === 0100000 && $entryStat['nlink'] === 1, 'output_directory');
        }
    }

    /** Reject relative paths, aliases and links in every component before touching a file. */
    private function path(string $path): array
    {
        $this->require($path !== '' && strlen($path) <= 4096 && $path[0] === '/' && ! str_contains($path, "\0")
            && ! str_contains($path, '//') && ! str_ends_with($path, '/'), 'path');
        $cursor = '';
        $stat = null;
        foreach (explode('/', substr($path, 1)) as $component) {
            $this->require(! in_array($component, ['', '.', '..'], true), 'path');
            $cursor .= '/'.$component;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            $this->require($stat !== false && ($stat['mode'] & 0170000) !== 0120000, 'path');
        }
        $this->require($stat !== null && realpath($path) === $path, 'path');

        return $stat;
    }

    private function write(string $path, string $bytes): void
    {
        $pending = $path.'.pending';
        $this->require(! $this->exists($pending), 'output_pending_exists');
        $previous = umask(0077);
        try {
            $handle = @fopen($pending, 'xb');
        } finally {
            umask($previous);
        }
        $this->require($handle !== false, 'output_write');
        try {
            $this->require(chmod($pending, 0600), 'output_write');
            $offset = 0;
            while ($offset < strlen($bytes)) {
                $count = @fwrite($handle, substr($bytes, $offset));
                $this->require($count !== false && $count > 0, 'output_write');
                $offset += $count;
            }
            $this->require(@fflush($handle) && @fsync($handle), 'output_write');
        } finally {
            fclose($handle);
        }
        $this->require(! $this->exists($path) && @rename($pending, $path), 'output_write');
    }

    private function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    private function require(bool $condition, string $code): void
    {
        if (! $condition) {
            throw new InvalidArgumentException($code);
        }
    }
}
