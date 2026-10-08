<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Delivery\PreparedDeliveryStream;
use Throwable;

/**
 * Bounded POSIX spool for delivery snapshots: the 256 counterpart of the main-resident `PrepareTestDeliveryStream`.
 * A snapshot needs a held `flock` slot (so at most `slots` snapshots, named or unlinked, exist at once, bounded by
 * `slots` x `DeliveryAssetFiles::MAX_BYTES`), free space above the size plus a reserve, a read-only reopen of the
 * sealed file and a read-back hash taken from the spool itself, not only from the write path. The returned
 * stream keeps the slot lease until it is closed.
 *
 * Not final: the free-space probe and the flush are the seams the tests override, as for the main-resident adapter.
 */
class ProductionFreeGrantSpool
{
    /**
     * @param  callable(resource):void  $fill  Writes exactly `$bytes` bytes into the writable spool handle.
     */
    public function prepare(string $directory, int $slots, int $reserveBytes, string $sha256, int $bytes, int $deadline, callable $fill): PreparedDeliveryStream
    {
        $lease = $output = $reader = $path = $owned = null;
        try {
            if (DIRECTORY_SEPARATOR !== '/' || $slots < 1 || $reserveBytes < 0 || $bytes < 1 || realpath($directory) !== $directory
                || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
                throw new \UnexpectedValueException;
            }
            [$lease, $path] = $this->slot($directory, $slots);
            $space = $this->freeBytes($directory);
            ProductionFreeGrantException::require(is_numeric($space) && is_finite((float) $space) && $space >= $bytes + $reserveBytes, 'spool_space');
            $mask = umask(0077);
            try {
                $output = @fopen($path, 'x+b');
            } finally {
                umask($mask);
            }
            if (! is_resource($output)) {
                throw new \UnexpectedValueException;
            }
            $owned = fstat($output);
            $this->sameFile($path, $owned, 0600, 0);
            $fill($output);
            $this->within($deadline);
            if (! $this->flush($output)) {
                throw new \UnexpectedValueException;
            }
            $this->sameFile($path, fstat($output), 0600, $bytes);
            if (! @chmod($path, 0400)) {
                throw new \UnexpectedValueException;
            }
            $sealed = fstat($output);
            $this->sameFile($path, $sealed, 0400, $bytes);
            // Only the spool is reopened, read-only. The source is never reopened after it was checked.
            $reader = @fopen($path, 'rb');
            if (! is_resource($reader) || $this->identity(fstat($reader)) !== $this->identity($sealed)) {
                throw new \UnexpectedValueException;
            }
            fclose($output);
            $output = null;
            $this->readBack($reader, $sha256, $bytes, $deadline);
            $this->sameFile($path, fstat($reader), 0400, $bytes);
            $this->within($deadline);
            if (! $this->unlinkSpool($path)) {
                throw new \UnexpectedValueException;
            }
            $path = null;
            $detached = fstat($reader);
            if ($detached['nlink'] !== 0 || $detached['size'] !== $bytes || ($detached['mode'] & 07777) !== 0400
                || $detached['ino'] !== $sealed['ino'] || $detached['dev'] !== $sealed['dev'] || fseek($reader, 0) !== 0) {
                throw new \UnexpectedValueException;
            }
            $prepared = new PreparedDeliveryStream($reader, $sha256, $bytes, $lease);
            $reader = $lease = null;

            return $prepared;
        } catch (ProductionFreeGrantException $error) {
            throw $error;
        } catch (Throwable) {
            throw new ProductionFreeGrantException('artifact_unavailable');
        } finally {
            if ($path !== null && is_array($owned)) {
                clearstatcache(true, $path);
                $current = @lstat($path);
                // Remove only the snapshot this call exclusively created, never an unexpected replacement.
                if (is_array($current) && $current['dev'] === $owned['dev'] && $current['ino'] === $owned['ino']
                    && ($current['mode'] & 0170000) === 0100000 && $current['nlink'] === 1) {
                    @unlink($path);
                }
            }
            foreach ([$output, $reader, $lease] as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    }

    protected function freeBytes(string $directory): float|false
    {
        return @disk_free_space($directory);
    }

    protected function flush($handle): bool
    {
        return function_exists('fsync') && @fflush($handle) && @fsync($handle);
    }

    protected function unlinkSpool(string $path): bool
    {
        return @unlink($path);
    }

    /**
     * A fixed path per locked slot keeps crash leftovers from accumulating across fresh names. A slot whose
     * snapshot path already exists is skipped and never repaired or deleted.
     *
     * @return array{0:resource,1:string}
     */
    private function slot(string $directory, int $slots): array
    {
        for ($slot = 0; $slot < $slots; $slot++) {
            $candidate = $this->lease($directory.'/slot-'.$slot.'.lock');
            if (! @flock($candidate, LOCK_EX | LOCK_NB)) {
                fclose($candidate);

                continue;
            }
            $snapshot = $directory.'/slot-'.$slot.'.snapshot';
            clearstatcache(true, $snapshot);
            if (@lstat($snapshot) !== false) {
                fclose($candidate);

                continue;
            }

            return [$candidate, $snapshot];
        }

        throw new ProductionFreeGrantException('spool_busy');
    }

    /** Read-only descriptor on a fixed 0400 lock file; `flock` on it is the slot lease. */
    private function lease(string $path)
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            $mask = umask(0077);
            try {
                $created = @fopen($path, 'x+b');
            } finally {
                umask($mask);
            }
            if (is_resource($created)) {
                try {
                    $this->sameFile($path, fstat($created), 0600, 0);
                    if (! @chmod($path, 0400)) {
                        throw new \UnexpectedValueException;
                    }
                } finally {
                    fclose($created);
                }
            }
            clearstatcache(true, $path);
            $before = @lstat($path);
        }
        $this->sameFile($path, $before, 0400, 0);
        $input = @fopen($path, 'rb');
        if (! is_resource($input)) {
            throw new \UnexpectedValueException;
        }
        try {
            if ($this->identity($before) !== $this->identity(fstat($input))) {
                throw new \UnexpectedValueException;
            }
            $this->sameFile($path, fstat($input), 0400, 0);

            return $input;
        } catch (Throwable $error) {
            fclose($input);
            throw $error;
        }
    }

    private function readBack($input, string $sha256, int $bytes, int $deadline): void
    {
        $hash = hash_init('sha256');
        $seen = 0;
        while (! feof($input)) {
            $this->within($deadline);
            $chunk = @fread($input, min(1048576, $bytes - $seen + 1));
            if (! is_string($chunk) || ($chunk === '' && ! feof($input))) {
                throw new \UnexpectedValueException;
            }
            $seen += strlen($chunk);
            if ($seen > $bytes) {
                throw new ProductionFreeGrantException('artifact_drift');
            }
            hash_update($hash, $chunk);
        }
        ProductionFreeGrantException::require($seen === $bytes && hash_equals($sha256, hash_final($hash)), 'artifact_drift');
    }

    private function sameFile(string $path, array|false $opened, int $mode, int $bytes): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        if (! is_array($opened) || ! is_array($current) || ($current['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0170000) !== 0100000 || ($current['mode'] & 07777) !== $mode
            || ($opened['mode'] & 07777) !== $mode || $current['nlink'] !== 1 || $opened['nlink'] !== 1
            || $current['size'] !== $bytes || $opened['size'] !== $bytes || realpath($path) !== $path
            || $this->identity($opened) !== $this->identity($current)) {
            throw new \UnexpectedValueException;
        }
    }

    private function identity(array|false $stat): array
    {
        return is_array($stat) ? array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'nlink', 'uid', 'gid', 'mtime', 'ctime'])) : [];
    }

    private function within(int $deadline): void
    {
        ProductionFreeGrantException::require(hrtime(true) <= $deadline, 'expired');
    }
}
