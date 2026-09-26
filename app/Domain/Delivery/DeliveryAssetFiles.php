<?php

namespace App\Domain\Delivery;

use Throwable;

/** Read-only local adapter. A digest is an observation, not continuing storage or download authority. */
class DeliveryAssetFiles
{
    public const MAX_BYTES = 1073741824;
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function verify(array $entry, ?int $deadline = null): void
    {
        ActivationPolicy::outsideTransactions();
        $input = null;
        try {
            $deadline ??= hrtime(true) + 300_000_000_000;
            $filename = match ($entry['role'] ?? null) {
                'master_wav' => 'master.wav', 'download_mp3' => 'delivery.mp3', 'stems_zip' => 'stems.zip', default => null,
            };
            if ($filename === null || ($entry['disk'] ?? null) !== 'local'
                || ! is_string($entry['storage_path'] ?? null)
                || ! preg_match('~\Amedia/revisions/'.self::UUID.'/'.preg_quote($filename, '~').'\z~D', $entry['storage_path'])
                || ! is_int($entry['size_bytes'] ?? null) || $entry['size_bytes'] < 1 || $entry['size_bytes'] > self::MAX_BYTES
                || ! is_string($entry['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $entry['sha256'])) { throw new \UnexpectedValueException; }
            $root = $this->root();
            $directories = $this->directories($root, dirname($entry['storage_path']));
            $path = $root.'/'.$entry['storage_path'];
            clearstatcache(true, $path); $before = @lstat($path);
            $this->sameFile($path, $before, $root, $entry['size_bytes']);
            $input = @fopen($path, 'rb');
            if (! is_resource($input)) { throw new \UnexpectedValueException; }
            $opened = fstat($input);
            if ($this->identity($before) !== $this->identity($opened)) { throw new \UnexpectedValueException; }
            $this->sameFile($path, $opened, $root, $entry['size_bytes']);
            $hash = hash_init('sha256'); $bytes = 0;
            while (! feof($input)) {
                if (hrtime(true) > $deadline) { throw new \UnexpectedValueException; }
                $chunk = $this->readChunk($input, min(1048576, $entry['size_bytes'] - $bytes + 1));
                if (! is_string($chunk) || ($chunk === '' && ! feof($input))) { throw new \UnexpectedValueException; }
                $bytes += strlen($chunk);
                if ($bytes > $entry['size_bytes']) { throw new \UnexpectedValueException; }
                hash_update($hash, $chunk);
            }
            if (hrtime(true) > $deadline || $bytes !== $entry['size_bytes'] || ! hash_equals($entry['sha256'], hash_final($hash))) { throw new \UnexpectedValueException; }
            $this->sameFile($path, fstat($input), $root, $entry['size_bytes']);
            if ($this->identity($opened) !== $this->identity(fstat($input)) || $root !== $this->root()
                || $directories !== $this->directories($root, dirname($entry['storage_path']))) { throw new \UnexpectedValueException; }
        } catch (Throwable) {
            throw new DeliveryException('asset_unavailable');
        } finally {
            if (is_resource($input)) { fclose($input); }
        }
    }

    /** Bounded descriptor read; override in deterministic tests to exercise replacement during hashing. */
    protected function readChunk($input, int $length): string|false
    {
        return @fread($input, $length);
    }

    private function root(): string
    {
        $disk = config('filesystems.disks.local');
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ($disk['serve'] ?? false) !== false
            || ($disk['visibility'] ?? 'private') !== 'private' || ($disk['prefix'] ?? '') !== '') { throw new \UnexpectedValueException; }
        // Resolving Storage::disk can create a missing local root in Flysystem. This read-only adapter never resolves it.
        if (! is_string($disk['root'] ?? null)) { throw new \UnexpectedValueException; }
        $path = rtrim($disk['root'], '/');
        if (! str_starts_with($path, '/') || str_contains($path, '//') || $path === '') { throw new \UnexpectedValueException; }
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            if ($component === '' || $component === '.' || $component === '..') { throw new \UnexpectedValueException; }
            $current .= '/'.$component; $this->directory($current, false);
        }
        $real = realpath($path);
        if ($real !== $path || ((@fileperms($path) ?: 0) & 0022) !== 0) { throw new \UnexpectedValueException; }
        $served = [public_path(), storage_path('app/public')];
        foreach ((array) config('filesystems.disks', []) as $other) {
            if (is_array($other) && ($other['driver'] ?? null) === 'local'
                && (($other['serve'] ?? false) === true || ($other['visibility'] ?? null) === 'public')) {
                $served[] = $other['root'] ?? '';
            }
        }
        // Start with a set: duplicate configured roots must not cancel newly discovered links in the growth check.
        $served = array_values(array_unique(array_map(fn ($root) => $this->potentialPath($root), $served)));
        $links = (array) config('filesystems.links', []);
        // Follow configured public link chains in either declaration order. Do not resolve the final link itself:
        // its location, not its already-resolved private target, establishes that it is publicly reachable.
        for ($pass = 0; $pass <= count($links); $pass++) {
            $before = count($served);
            foreach ($links as $link => $target) {
                if (! is_string($link) || ! str_starts_with($link, '/') || str_contains($link, "\0")) { throw new \UnexpectedValueException; }
                $location = rtrim($this->potentialPath(dirname($link)), '/').'/'.basename($link);
                foreach ($served as $public) {
                    if ($location === $public || str_starts_with($location, $public.'/')) {
                        $served[] = $this->potentialPath($target); break;
                    }
                }
            }
            $served = array_values(array_unique($served));
            if (count($served) === $before) { break; }
        }
        foreach ($served as $public) {
            if ($public === '/' || $real === $public || str_starts_with($real, $public.'/') || str_starts_with($public, $real.'/')) { throw new \UnexpectedValueException; }
        }

        return $real;
    }

    /** Resolve existing ancestors too: an absent future subtree may still lie under a public symlink. */
    private function potentialPath(mixed $path): string
    {
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, "\0")) { throw new \UnexpectedValueException; }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') { continue; }
            if ($part === '..') { throw new \UnexpectedValueException; }
            $parts[] = $part;
        }
        $path = '/'.implode('/', $parts); $tail = [];
        while (($real = realpath($path)) === false) {
            // A dangling link can expose a private subtree as soon as it is created; never discard that link.
            if ($path === '/' || is_link($path)) { throw new \UnexpectedValueException; }
            array_unshift($tail, basename($path)); $path = dirname($path);
        }

        return $tail === [] ? $real : rtrim($real, '/').'/'.implode('/', $tail);
    }

    private function directories(string $root, string $relative): array
    {
        $result = [$root => $this->directory($root, false)]; $path = $root;
        foreach (explode('/', $relative) as $component) {
            $path .= '/'.$component; $result[$path] = $this->directory($path, true);
        }

        return $result;
    }

    private function directory(string $path, bool $private): array
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($private && ($stat['mode'] & 07777) !== 0700)) { throw new \UnexpectedValueException; }

        // Directory content timestamps can change when another worker appends a different immutable revision.
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']));
    }

    private function sameFile(string $path, array|false $opened, string $root, int $bytes): void
    {
        clearstatcache(true, $path); $current = @lstat($path); $real = realpath($path);
        if (! is_array($opened) || ! is_array($current) || ($opened['mode'] & 0170000) !== 0100000
            || ($current['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || $current['nlink'] !== 1
            || ($opened['mode'] & 07777) !== 0400 || ($current['mode'] & 07777) !== 0400
            || $opened['size'] !== $bytes || $current['size'] !== $bytes
            || $this->identity($opened) !== $this->identity($current)
            || $real !== $path || ! str_starts_with($real, $root.'/')) { throw new \UnexpectedValueException; }
    }

    private function identity(array|false $stat): array
    {
        return is_array($stat) ? array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'nlink', 'uid', 'gid', 'mtime', 'ctime'])) : [];
    }
}
