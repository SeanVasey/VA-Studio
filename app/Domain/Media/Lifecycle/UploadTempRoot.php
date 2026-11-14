<?php

namespace App\Domain\Media\Lifecycle;

/**
 * Resolves the dedicated resumable-upload temp root, `resumable/` on the private local disk, without creating anything.
 * A missing private root or temp root is reported as absent (null); every unsafe layout is refused.
 */
final class UploadTempRoot
{
    public const DIRECTORY = 'resumable';

    public function resolve(int $euid): ?string
    {
        $disk = config('filesystems.disks.local');
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ($disk['serve'] ?? false) !== false
            || ($disk['visibility'] ?? 'private') !== 'private' || ! is_string($disk['root'] ?? null)) {
            throw new UploadSweepRefused('private_disk');
        }
        $configured = rtrim($disk['root'], '/');
        if (! str_starts_with($configured, '/') || str_contains($configured, '//') || preg_match('~(?:\A|/)\.\.?(?:/|\z)~', $configured) === 1) {
            throw new UploadSweepRefused('root_unsafe');
        }
        clearstatcache(true, $configured);
        if (@lstat($configured) === false) {
            return null;
        }
        // A shared storage root may be a link (as PrivateMediaFiles::root() accepts); resolve it once and check the real path.
        $root = realpath($configured);
        clearstatcache(true, (string) $root);
        $stat = $root === false ? false : @lstat($root);
        if ($stat === false || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0022) !== 0) {
            throw new UploadSweepRefused('root_unsafe');
        }
        foreach ($this->publicRoots() as $public) {
            if ($public !== '' && ($root === $public || str_starts_with($root.'/', $public.'/'))) {
                throw new UploadSweepRefused('root_public');
            }
        }
        // The temp root itself is never a link: it must be a real private directory directly under the resolved root.
        $temp = $root.'/'.self::DIRECTORY;
        clearstatcache(true, $temp);
        $stat = @lstat($temp);
        if ($stat === false) {
            return null;
        }
        if (($stat['mode'] & 0177777) !== 0040700 || $stat['uid'] !== $euid || realpath($temp) !== $temp) {
            throw new UploadSweepRefused('temp_root_unsafe');
        }

        return $temp;
    }

    /** @return list<string> the web root, the public disk, every served or public local disk and every configured public link */
    private function publicRoots(): array
    {
        $roots = [public_path(), storage_path('app/public')];
        foreach ((array) config('filesystems.disks', []) as $other) {
            if (is_array($other) && ($other['driver'] ?? null) === 'local' && is_string($other['root'] ?? null)
                && (($other['serve'] ?? false) === true || ($other['visibility'] ?? null) === 'public')) {
                $roots[] = $other['root'];
            }
        }
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            $roots[] = (string) $link;
            $roots[] = (string) $target;
        }

        return array_values(array_unique(array_map(function (string $path): string {
            $path = rtrim($path, '/');
            $real = $path === '' ? false : realpath($path);

            return $real === false ? $path : $real;
        }, $roots)));
    }
}
