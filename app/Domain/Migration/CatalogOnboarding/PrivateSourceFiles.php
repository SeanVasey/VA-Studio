<?php

declare(strict_types=1);

namespace App\Domain\Migration\CatalogOnboarding;

use InvalidArgumentException;
use Throwable;

/** Read-only private artifact admission. No source acquisition, mutation or application bootstrap. */
final class PrivateSourceFiles
{
    public function snapshot(string $directory, string $manifestName = 'catalog.json'): array
    {
        try {
            $identity = $this->directory($directory);
            $this->require(! str_contains($manifestName, '/') && ! in_array($manifestName, ['', '.', '..'], true));
            $manifest = $this->read($directory.'/'.$manifestName, 1048576, true);
            $snapshot = (new NormalizedSourceSnapshot)->decode($manifest['bytes']);
            $total = array_sum(array_column($snapshot['artifacts'], 'bytes'));
            $this->require($total <= 1073741824);
            $artifacts = [];
            foreach ($snapshot['artifacts'] as $artifact) {
                $path = $directory.'/'.$artifact['relative_path'];
                $this->privateChildren($directory, dirname($path));
                $proof = $this->read($path, $artifact['bytes']);
                $this->require($proof['identity']['size'] === $artifact['bytes'] && hash_equals($artifact['sha256'], $proof['sha256']));
                $artifacts[$artifact['artifact_id']] = $proof;
            }
            $this->require($this->directory($directory) === $identity);

            return ['directory' => $directory, 'directory_identity' => $identity, 'manifest_name' => $manifestName,
                'source_sha256' => $manifest['sha256'], 'manifest_identity' => $manifest['identity'], 'snapshot' => $snapshot,
                'artifacts' => $artifacts];
        } catch (Throwable) {
            throw new InvalidArgumentException('catalog_source_files_invalid');
        }
    }

    public function unchanged(array $proof): void
    {
        $current = $this->snapshot($proof['directory'], $proof['manifest_name']);
        $this->require($current === $proof);
    }

    private function directory(string $directory): array
    {
        $stat = $this->path($directory);
        $this->require(($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === 0700);
        $this->owner($stat);
        $parent = dirname($directory);
        $parentStat = $this->path($parent);
        $this->owner($parentStat);
        $this->require(($parentStat['mode'] & 0022) === 0);
        for ($ancestor = $directory; ; $ancestor = dirname($ancestor)) {
            $entry = $this->path($ancestor);
            $this->require(($entry['mode'] & 0170000) === 0040000 && (($entry['mode'] & 0022) === 0
                || ($entry['uid'] === 0 && ($entry['mode'] & 01000) !== 0)) && @lstat($ancestor.'/.git') === false);
            if ($ancestor === '/') {
                break;
            }
        }

        return ['dev' => $stat['dev'], 'ino' => $stat['ino'], 'uid' => $stat['uid'], 'mode' => $stat['mode']];
    }

    private function privateChildren(string $directory, string $parent): void
    {
        $this->require($parent === $directory || str_starts_with($parent, $directory.'/'));
        for ($cursor = $parent; $cursor !== $directory; $cursor = dirname($cursor)) {
            $stat = $this->path($cursor);
            $this->owner($stat);
            $this->require(($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === 0700);
        }
    }

    private function path(string $path): array
    {
        $this->require(str_starts_with($path, '/') && ! preg_match('/[\x00-\x1f\x7f]/', $path)
            && ! str_contains($path, '//') && ($path === '/' || ! str_ends_with($path, '/')));
        if ($path === '/') {
            $stat = @lstat('/');
            $this->require($stat !== false);

            return $stat;
        }
        $cursor = '';
        foreach (explode('/', substr($path, 1)) as $component) {
            $this->require(! in_array($component, ['', '.', '..'], true));
            $cursor .= '/'.$component;
            clearstatcache(true, $cursor);
            $stat = @lstat($cursor);
            $this->require($stat !== false && ($stat['mode'] & 0170000) !== 0120000);
        }
        $this->require(realpath($path) === $path);

        return $stat;
    }

    private function owner(array $stat): void
    {
        $this->require(function_exists('posix_geteuid') && $stat['uid'] === posix_geteuid());
    }

    private function read(string $path, int $maximum, bool $capture = false): array
    {
        $expected = $this->path($path);
        $this->owner($expected);
        $this->require(($expected['mode'] & 0170000) === 0100000 && ($expected['mode'] & 07777) === 0600
            && $expected['nlink'] === 1 && $expected['size'] > 0 && $expected['size'] <= $maximum);
        $handle = @fopen($path, 'rb');
        $this->require($handle !== false);
        try {
            $before = fstat($handle);
            $this->require($before !== false && $this->same($before, $expected));
            $digest = hash_init('sha256');
            $bytes = '';
            $read = 0;
            while (! feof($handle)) {
                $chunk = fread($handle, 1048576);
                $this->require($chunk !== false && ($chunk !== '' || feof($handle)));
                $read += strlen($chunk);
                $this->require($read <= $maximum);
                hash_update($digest, $chunk);
                if ($capture) {
                    $bytes .= $chunk;
                }
            }
            $after = fstat($handle);
            $named = $this->path($path);
            $this->require($after !== false && $this->same($after, $before) && $this->same($named, $after) && $read === $after['size']);

            return ['sha256' => hash_final($digest), 'identity' => array_intersect_key($after, array_flip(['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime']))]
                + ($capture ? ['bytes' => $bytes] : []);
        } finally {
            fclose($handle);
        }
    }

    private function same(array $left, array $right): bool
    {
        foreach (['dev', 'ino', 'uid', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            if ($left[$field] !== $right[$field]) {
                return false;
            }
        }

        return true;
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new InvalidArgumentException('catalog_source_files_invalid');
        }
    }
}
