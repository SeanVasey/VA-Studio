<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Delivery\PreparedDeliveryStream;
use Throwable;

/**
 * Bounded POSIX spool for delivery snapshots: the 256 counterpart of the main-resident `PrepareTestDeliveryStream`.
 * A snapshot needs a held `flock` slot (so at most `slots` snapshots, named or unlinked, exist at once, bounded by
 * `slots` x `DeliveryAssetFiles::MAX_BYTES`), free space above the size plus a reserve after subtracting what every
 * other active slot has reserved and not yet written (admission is serialized by a short global lock), a read-only reopen of the
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
        $lease = $output = $reader = $path = $owned = $reservation = null;
        try {
            if (DIRECTORY_SEPARATOR !== '/' || $slots < 1 || $reserveBytes < 0 || $bytes < 1 || realpath($directory) !== $directory
                || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
                throw new \UnexpectedValueException;
            }
            [$lease, $path, $reservation] = $this->admit($directory, $slots, $reserveBytes, $bytes);
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
            // The finished snapshot is now part of what the disk reports, so its reservation is released.
            $this->reserve($reservation, 0);
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
            if (is_resource($reservation)) {
                try {
                    $this->reserve($reservation, 0);
                } catch (Throwable) {
                    // A reservation that cannot be cleared stays until the slot's next holder overwrites it.
                }
            }
            foreach ([$output, $reader, $reservation, $lease] as $handle) {
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
     * Slot and disk admission under one short global lock, so concurrent snapshots cannot each pass the free-space
     * probe alone: the bytes reserved by every other active slot are subtracted before this size is admitted.
     *
     * @return array{0:resource,1:string,2:resource} the slot lease, its snapshot path and its reservation handle
     */
    private function admit(string $directory, int $slots, int $reserveBytes, int $bytes): array
    {
        $gate = $this->lease($directory.'/admission.lock');
        try {
            if (! @flock($gate, LOCK_EX)) {
                throw new \UnexpectedValueException;
            }
            [$lease, $snapshot, $number] = $this->slot($directory, $slots);
            $reservation = null;
            try {
                $space = $this->freeBytes($directory);
                $pending = $this->pending($directory, $number);
                ProductionFreeGrantException::require(is_numeric($space) && is_finite((float) $space) && $space - $pending >= $bytes + $reserveBytes, 'spool_space');
                $reservation = $this->reservation($directory.'/slot-'.$number.'.reserve');
                $this->reserve($reservation, $bytes);
            } catch (Throwable $error) {
                foreach ([$reservation, $lease] as $handle) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                }
                throw $error;
            }

            return [$lease, $snapshot, $reservation];
        } finally {
            @flock($gate, LOCK_UN);
            fclose($gate);
        }
    }

    /**
     * A fixed path per locked slot keeps crash leftovers from accumulating across fresh names. Holding the slot's
     * flock means its previous holder is gone, so a residual snapshot at exactly this slot's path is removed, and a
     * residual reservation sidecar of the wrong size is removed for `reservation()` to recreate. Only a regular
     * single-link file owned by this process user is removed. A slot whose snapshot or sidecar is anything else is
     * skipped (never repaired) and the next slot is tried, so one unusable slot cannot block admission to the others.
     *
     * @return array{0:resource,1:string,2:int}
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
            $residual = @lstat($snapshot);
            $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            if ($residual !== false) {
                if (($residual['mode'] & 0170000) !== 0100000 || $residual['nlink'] !== 1 || $residual['uid'] !== $owner || ! @unlink($snapshot)) {
                    fclose($candidate);

                    continue;
                }
            }
            $sidecar = $directory.'/slot-'.$slot.'.reserve';
            clearstatcache(true, $sidecar);
            $reserve = @lstat($sidecar);
            if ($reserve !== false && (($reserve['mode'] & 0170000) !== 0100000 || $reserve['nlink'] !== 1 || $reserve['uid'] !== $owner
                || ($reserve['size'] !== 20 && ! @unlink($sidecar)))) {
                fclose($candidate);

                continue;
            }

            return [$candidate, $snapshot, $slot];
        }

        throw new ProductionFreeGrantException('spool_busy');
    }

    /** Bytes still to be written by every other slot that is held right now. */
    private function pending(string $directory, int $own): int
    {
        $total = 0;
        foreach (glob($directory.'/slot-*.lock') ?: [] as $lock) {
            if (preg_match('~/slot-(\d{1,3})\.lock\z~D', $lock, $match) !== 1 || (int) $match[1] === $own) {
                continue;
            }
            $probe = $this->lease($lock);
            try {
                if (@flock($probe, LOCK_EX | LOCK_NB)) {
                    @flock($probe, LOCK_UN);

                    continue;
                }
            } finally {
                fclose($probe);
            }
            $total += $this->reserved($directory.'/slot-'.$match[1].'.reserve');
        }

        return $total;
    }

    private function reserved(string $path): int
    {
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            return 0;
        }
        $handle = @fopen($path, 'rb');
        if (! is_resource($handle)) {
            throw new \UnexpectedValueException;
        }
        try {
            $this->sameFile($path, fstat($handle), 0600, 20);
            $value = fread($handle, 20);
            if (! is_string($value) || preg_match('/\A[0-9]{20}\z/D', $value) !== 1) {
                throw new \UnexpectedValueException;
            }

            return (int) $value;
        } finally {
            fclose($handle);
        }
    }

    /**
     * The slot's fixed 20-byte reservation sidecar, writable only while its slot lock is held. Called under both the
     * slot lock and the admission lock, so no live holder is writing it. A sidecar is created under a fresh name and
     * renamed into place, so a crash never leaves a partial file at the fixed path; a residual of the wrong size left
     * by an older crash is reclaimed under the same rule as a residual snapshot (regular, single-link, owned by this
     * process user) and recreated. Anything else keeps the slot unusable.
     */
    private function reservation(string $path)
    {
        clearstatcache(true, $path);
        $residual = @lstat($path);
        if ($residual !== false && $residual['size'] !== 20) {
            $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
            if (($residual['mode'] & 0170000) !== 0100000 || $residual['nlink'] !== 1 || $residual['uid'] !== $owner || ! @unlink($path)) {
                throw new \UnexpectedValueException;
            }
            $residual = false;
        }
        if ($residual === false) {
            $temporary = $path.'.'.bin2hex(random_bytes(16)).'.tmp';
            $mask = umask(0077);
            try {
                $created = @fopen($temporary, 'x+b');
            } finally {
                umask($mask);
            }
            if (! is_resource($created)) {
                throw new \UnexpectedValueException;
            }
            $written = @fwrite($created, str_repeat('0', 20)) === 20 && @fflush($created);
            fclose($created);
            if (! $written || ! @rename($temporary, $path)) {
                @unlink($temporary);
                throw new \UnexpectedValueException;
            }
        }
        $handle = @fopen($path, 'r+b');
        if (! is_resource($handle)) {
            throw new \UnexpectedValueException;
        }
        try {
            $this->sameFile($path, fstat($handle), 0600, 20);

            return $handle;
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    private function reserve($handle, int $bytes): void
    {
        if (fseek($handle, 0) !== 0 || @fwrite($handle, sprintf('%020d', $bytes)) !== 20 || ! @fflush($handle)) {
            throw new \UnexpectedValueException;
        }
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
