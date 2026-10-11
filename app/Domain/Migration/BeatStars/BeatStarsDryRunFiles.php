<?php

declare(strict_types=1);

namespace App\Domain\Migration\BeatStars;

use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use InvalidArgumentException;
use Throwable;

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
        $mapping = $this->source($mappingPath, null, BeatStarsExportMapping::MAX_BYTES);
        $export = $this->source($exportPath, $mapping, BeatStarsExportNormalizer::MAX_EXPORT_BYTES);
        $pinned = $this->directory($outputDirectory);
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
        $this->require($this->sameInode($pinned, $this->directory($outputDirectory)), 'output_directory');
        // Stage every output, then publish them; on any failure remove every file this run created, so a
        // refusal never leaves a partial report set or a .pending file behind. A file is removed only while
        // its path still names the inode this run wrote: if the directory was swapped after publication,
        // nothing in the replacement is touched and the published outputs stay in the moved original.
        $created = [];
        try {
            foreach ($written as $name) {
                $created[] = $this->stage($outputDirectory.'/'.$name, $outputs[$name]);
            }
            foreach ($written as $index => $name) {
                $path = $outputDirectory.'/'.$name;
                $this->require(! $this->exists($path) && @rename($created[$index]['path'], $path), 'output_write');
                $created[$index]['path'] = $path;
            }
            $this->require($this->sameInode($pinned, $this->path($outputDirectory)), 'output_directory');
        } catch (Throwable $error) {
            foreach ($created as $file) {
                clearstatcache(true, $file['path']);
                $current = @lstat($file['path']);
                if ($current !== false && $this->sameInode($file, $current)) {
                    @unlink($file['path']);
                }
            }
            throw $error;
        }

        return ['result' => $result, 'written' => $written, 'unchanged' => $unchanged];
    }

    /**
     * A real export and its mapping are private source material (the mapping names the account and the
     * operator): each must live outside this checkout as a caller-owned, owner-only file with a single link.
     * Only a mapping that declares a synthetic fixture may be read, with its export, from tests/Fixtures/.
     * $mapping is null when $path is the mapping itself.
     */
    private function source(string $path, ?string $mapping, int $maximum): string
    {
        $bytes = $this->input($path, $maximum);
        $repository = dirname(__DIR__, 4);
        if ($path === $repository || str_starts_with($path, $repository.'/')) {
            $this->require(str_starts_with($path, $repository.'/tests/Fixtures/')
                && (new BeatStarsExportMapping)->decode($mapping ?? $bytes)['acquisition_method'] === 'synthetic_fixture',
                'input_inside_repository');

            return $bytes;
        }
        // The shared private-source boundary: a caller-owned 0600 single-link file in a caller-owned 0700
        // directory whose parent is caller-owned and not group- or world-writable; every ancestor must be
        // non-group/world-writable unless root-owned and sticky, and none may contain .git. The file is read
        // through one descriptor whose uid, mode, link count and identity are checked on the same stat it reads.
        try {
            $proof = (new PrivateSourceFiles)->protectedFile($path, $maximum);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException('input_not_private');
        }
        $this->require($proof['bytes'] === $bytes, 'input_changed');

        return $proof['bytes'];
    }

    /**
     * Read one regular file whose identity cannot change across the read: the named file, the open
     * descriptor before reading and the descriptor afterwards must share device, inode, size, mtime and
     * ctime, so the hashed bytes are exactly the retained file's. This is only the identity half of
     * PrivateSourceFiles::read; a real input's privacy goes through PrivateSourceFiles itself (source()).
     */
    private function input(string $path, int $maximum): string
    {
        $stat = $this->path($path);
        $this->require(($stat['mode'] & 0170000) === 0100000 && $stat['size'] > 0 && $stat['size'] <= $maximum, 'input_file');
        $handle = @fopen($path, 'rb');
        $this->require($handle !== false, 'input_file');
        try {
            $before = fstat($handle);
            $this->require($before !== false && $this->same($before, $stat), 'input_changed');
            $bytes = stream_get_contents($handle, $maximum + 1);
            $after = fstat($handle);
            $this->require(is_string($bytes) && $after !== false && $this->same($after, $before), 'input_changed');
        } finally {
            fclose($handle);
        }
        $this->require($this->same($this->path($path), $after) && strlen($bytes) === $after['size'], 'input_changed');

        return $bytes;
    }

    private function same(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'size', 'mtime', 'ctime'] as $key) {
            if ($left[$key] !== $right[$key]) {
                return false;
            }
        }

        return true;
    }

    /**
     * The output directory must be a caller-owned 0700 directory outside every Git working tree, and no
     * other local user may rename or replace it: each ancestor is owned by the caller or root and is not
     * group- or world-writable unless it is a root-owned sticky directory such as /tmp.
     */
    private function directory(string $path): array
    {
        $stat = $this->path($path);
        $this->require(($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === 0700
            && function_exists('posix_geteuid') && $stat['uid'] === posix_geteuid(), 'output_directory');
        $repository = dirname(__DIR__, 4);
        $this->require($path !== $repository && ! str_starts_with($path, $repository.'/'), 'output_inside_repository');
        // Reports hold source ids, raw metadata, prices and rights references: no Git working tree may hold them.
        for ($ancestor = $path; ; $ancestor = dirname($ancestor)) {
            clearstatcache(true, $ancestor.'/.git');
            $this->require(@lstat($ancestor.'/.git') === false, 'output_inside_repository');
            if ($ancestor !== $path) {
                clearstatcache(true, $ancestor);
                $entry = @lstat($ancestor);
                $this->require($entry !== false && ($entry['mode'] & 0170000) === 0040000
                    && in_array($entry['uid'], [0, posix_geteuid()], true)
                    && (($entry['mode'] & 0022) === 0 || ($entry['uid'] === 0 && ($entry['mode'] & 01000) !== 0)), 'output_directory');
            }
            if ($ancestor === '/') {
                break;
            }
        }
        $entries = @scandir($path);
        $this->require($entries !== false, 'output_directory');
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->require(in_array($entry, self::OUTPUTS, true), 'output_directory_not_empty');
            $entryStat = $this->path($path.'/'.$entry);
            // A retained output must still be private: a regular, single-link, caller-owned 0600 file.
            $this->require(($entryStat['mode'] & 0170000) === 0100000 && $entryStat['nlink'] === 1
                && ($entryStat['mode'] & 07777) === 0600 && $entryStat['uid'] === posix_geteuid(), 'output_directory');
        }

        return $stat;
    }

    private function sameInode(array $left, array $right): bool
    {
        return $left['dev'] === $right['dev'] && $left['ino'] === $right['ino'];
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

    /**
     * Write one complete, synced, 0600 `.pending` file next to $path.
     *
     * @return array{path: string, dev: int, ino: int}
     */
    private function stage(string $path, string $bytes): array
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
        } catch (Throwable $error) {
            fclose($handle);
            @unlink($pending);
            throw $error;
        }
        $stat = fstat($handle);
        fclose($handle);
        if ($stat === false) {
            @unlink($pending);
            $this->require(false, 'output_write');
        }

        return ['path' => $pending, 'dev' => $stat['dev'], 'ino' => $stat['ino']];
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
