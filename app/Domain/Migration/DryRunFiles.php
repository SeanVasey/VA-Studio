<?php

declare(strict_types=1);

namespace App\Domain\Migration;

use App\Support\CanonicalJson;
use InvalidArgumentException;
use Throwable;

/** Local synthetic evidence only. No application boot, source acquisition or import writes. */
final class DryRunFiles
{
    private const INPUT_BYTES = 1048576;

    private const CHECKPOINT_BYTES = 4194304;

    private const LOCK_CONTENT = "vasey-synthetic-catalog-dry-run-lock-v1\n";

    public function run(string $manifestPath, string $targetPath, string $outputDirectory, int $limit = 1000): array
    {
        if ($limit < 1 || $limit > 1000) {
            $this->invalid();
        }

        $manifest = $this->read($manifestPath, self::INPUT_BYTES);
        $target = $this->read($targetPath, self::INPUT_BYTES);
        $planner = new CatalogDryRun;
        $plan = $planner->plan($manifest['bytes'], $target['bytes']);
        $directoryIdentity = $this->directory($outputDirectory);
        $this->inventory($outputDirectory);
        $lock = $this->lock($outputDirectory.'/checkpoint.lock');

        try {
            $this->unchangedDirectory($outputDirectory, $directoryIdentity);
            $this->inventory($outputDirectory);
            $this->sameFile($lock, $outputDirectory.'/checkpoint.lock', true);
            $path = $outputDirectory.'/checkpoint.json';
            $retained = $this->exists($path) ? $this->read($path, self::CHECKPOINT_BYTES, true) : null;
            $checkpoint = $planner->checkpoint($plan, $retained === null ? null : $this->decode($retained['bytes']), $limit);
            $bytes = CanonicalJson::encode($checkpoint)."\n";

            if (strlen($bytes) > self::CHECKPOINT_BYTES) {
                $this->invalid();
            }
            if ($retained !== null && $retained['bytes'] === $bytes) {
                return $checkpoint;
            }

            $this->replace($outputDirectory, $directoryIdentity, $lock, $retained, $bytes);

            return $checkpoint;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function decode(string $bytes): array
    {
        try {
            $decoded = json_decode($bytes, false, 64, JSON_THROW_ON_ERROR);
            $canonical = CanonicalJson::encode($decoded);
            if (! $decoded instanceof \stdClass || ($bytes !== $canonical && $bytes !== $canonical."\n")) {
                $this->invalid();
            }

            // Keep nested object/list distinctions for the planner's exact prefix comparison.
            return get_object_vars($decoded);
        } catch (Throwable) {
            $this->invalid();
        }
    }

    /** Reject aliases and links in every path component before opening a descriptor. */
    private function path(string $path): array
    {
        if ($path === '' || strlen($path) > 4096 || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '//') || str_ends_with($path, '/')) {
            $this->invalid();
        }
        $cursor = '';
        $components = explode('/', substr($path, 1));
        foreach ($components as $position => $component) {
            if ($component === '' || $component === '.' || $component === '..') {
                $this->invalid();
            }
            $cursor .= '/'.$component;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            if ($stat === false || ($stat['mode'] & 0170000) === 0120000
                || ($position < count($components) - 1 && ($stat['mode'] & 0170000) !== 0040000)) {
                $this->invalid();
            }
        }
        if (realpath($path) !== $path) {
            $this->invalid();
        }

        return $stat;
    }

    private function directory(string $path): array
    {
        $stat = $this->path($path);
        if (($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 07777) !== 0700
            || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())) {
            $this->invalid();
        }
        $repository = dirname(__DIR__, 3);
        if ($path === $repository || str_starts_with($path, $repository.'/')) {
            $this->invalid();
        }
        for ($ancestor = $path; $ancestor !== '/'; $ancestor = dirname($ancestor)) {
            $ancestorStat = @lstat($ancestor);
            if ($ancestorStat === false
                || (($ancestorStat['mode'] & 0022) !== 0 && ($ancestorStat['mode'] & 01000) === 0)
                || $this->exists($ancestor.'/.git')) {
                $this->invalid();
            }
        }

        return $stat;
    }

    private function unchangedDirectory(string $path, array $expected): void
    {
        $this->identity($this->directory($path), $expected);
    }

    private function inventory(string $directory, bool $pendingAllowed = false): void
    {
        $entries = @scandir($directory);
        if ($entries === false) {
            $this->invalid();
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (! in_array($entry, ['checkpoint.lock', 'checkpoint.json', 'checkpoint.pending'], true)
                || ($entry === 'checkpoint.pending' && ! $pendingAllowed)) {
                $this->invalid();
            }
            $this->regular($this->path($directory.'/'.$entry), true);
        }
    }

    private function regular(array $stat, bool $private = false): void
    {
        if (($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1
            || ($private && (($stat['mode'] & 07777) !== 0600
                || (function_exists('posix_geteuid') && $stat['uid'] !== posix_geteuid())))) {
            $this->invalid();
        }
    }

    private function identity(array $actual, array $expected): void
    {
        if ($actual['dev'] !== $expected['dev'] || $actual['ino'] !== $expected['ino']) {
            $this->invalid();
        }
    }

    private function sameFile($handle, string $path, bool $private = false): array
    {
        $pathStat = $this->path($path);
        $descriptorStat = fstat($handle);
        if ($descriptorStat === false) {
            $this->invalid();
        }
        $this->regular($pathStat, $private);
        $this->regular($descriptorStat, $private);
        $this->identity($pathStat, $descriptorStat);

        return $descriptorStat;
    }

    private function read(string $path, int $maximum, bool $private = false): array
    {
        $expected = $this->path($path);
        $this->regular($expected, $private);
        if ($expected['size'] > $maximum) {
            $this->invalid();
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->invalid();
        }
        try {
            $this->identity($this->sameFile($handle, $path, $private), $expected);
            $bytes = stream_get_contents($handle, $maximum + 1);
            $after = $this->sameFile($handle, $path, $private);
            if ($bytes === false || strlen($bytes) > $maximum || strlen($bytes) !== $after['size']
                || $expected['size'] !== $after['size'] || $expected['mtime'] !== $after['mtime']
                || $expected['ctime'] !== $after['ctime']) {
                $this->invalid();
            }

            return ['bytes' => $bytes, 'identity' => $after];
        } finally {
            fclose($handle);
        }
    }

    private function create(string $path)
    {
        $previousMask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousMask);
        }
        if ($handle === false) {
            $this->invalid();
        }

        return $handle;
    }

    private function lock(string $path)
    {
        $created = ! $this->exists($path);
        if ($created) {
            $handle = $this->create($path);
        } else {
            $this->regular($this->path($path), true);
            $handle = @fopen($path, 'r+b');
            if ($handle === false) {
                $this->invalid();
            }
        }
        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                $this->invalid();
            }
            $this->sameFile($handle, $path, true);
            if ($created) {
                $this->write($handle, self::LOCK_CONTENT);
            } elseif (stream_get_contents($handle, strlen(self::LOCK_CONTENT) + 1) !== self::LOCK_CONTENT) {
                $this->invalid();
            }

            return $handle;
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    private function write($handle, string $bytes): void
    {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = @fwrite($handle, substr($bytes, $offset));
            if ($written === false || $written === 0) {
                $this->invalid();
            }
            $offset += $written;
        }
        if (! @fflush($handle) || ! @fsync($handle)) {
            $this->invalid();
        }
    }

    private function replace(string $directory, array $directoryIdentity, $lock, ?array $retained, string $bytes): void
    {
        $pendingPath = $directory.'/checkpoint.pending';
        $path = $directory.'/checkpoint.json';
        $pending = $this->create($pendingPath);
        try {
            $pendingIdentity = $this->sameFile($pending, $pendingPath, true);
            $this->write($pending, $bytes);
            $this->identity($this->sameFile($pending, $pendingPath, true), $pendingIdentity);
            $this->unchangedDirectory($directory, $directoryIdentity);
            $this->inventory($directory, true);
            $this->sameFile($lock, $directory.'/checkpoint.lock', true);
            if ($retained === null) {
                if ($this->exists($path)) {
                    $this->invalid();
                }
            } else {
                $current = $this->read($path, self::CHECKPOINT_BYTES, true);
                $this->identity($current['identity'], $retained['identity']);
                if ($current['bytes'] !== $retained['bytes']) {
                    $this->invalid();
                }
            }
            if (! @rename($pendingPath, $path)) {
                $this->invalid();
            }
            $this->sameFile($pending, $path, true);
            $this->syncDirectory($directory, $directoryIdentity);
        } finally {
            // Retain an unrenamed pending file for explicit inspection after an uncertain outcome.
            fclose($pending);
        }
    }

    private function syncDirectory(string $path, array $expected): void
    {
        $this->unchangedDirectory($path, $expected);
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            $this->invalid();
        }
        try {
            $stat = fstat($handle);
            if ($stat === false) {
                $this->invalid();
            }
            $this->identity($stat, $expected);
            if (! @fsync($handle)) {
                $this->invalid();
            }
        } finally {
            fclose($handle);
        }
    }

    private function exists(string $path): bool
    {
        clearstatcache(true, $path);

        return @lstat($path) !== false;
    }

    private function invalid(): never
    {
        throw new InvalidArgumentException('dry_run_files_invalid');
    }
}
