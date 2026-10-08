<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Delivery\ActivationPolicy;
use App\Domain\Delivery\DeliveryAssetFiles;
use App\Domain\Delivery\DeliveryException;
use App\Domain\Delivery\PreparedDeliveryStream;
use Throwable;

/**
 * Paid-purpose POSIX snapshot adapter. Three held leases bound named plus unlinked snapshots to at most 3 GiB. Slot and
 * disk admission run under one short global lock: free space must cover this size plus the reserve after subtracting
 * what every other held slot has reserved and not yet written (its 20-byte `slot-N.reserve` sidecar), so concurrent
 * snapshots cannot each pass the free-space probe alone. A slot's reservation is released once its snapshot is complete
 * (the disk then reflects it) or when the preparation fails. Ported from the Free256 spool (`ProductionFreeGrantSpool`).
 */
class PaidGrantPrepareStream
{
    public const SLOTS = 3;

    public const RESERVE_BYTES = 16777216;

    /** Bound on a snapshot whose caller passes no deadline (tests and non-account callers). */
    public const MAX_SECONDS = 60;

    /** Ceiling on a caller's snapshot deadline: the largest accepted `paid-grants.snapshot_seconds`. */
    public const MAX_DEADLINE_SECONDS = 1800;

    /**
     * @param  int|null  $snapshotDeadline  Monotonic nanoseconds bounding only this snapshot's own copy and read-back;
     *                                      redemption passes its separate snapshot budget, capped here at
     *                                      MAX_DEADLINE_SECONDS. Null means MAX_SECONDS.
     * @param  string|null  $holder  64-hex digest naming the buyer account the snapshot is for. One buyer holds at most one
     *                               slot at a time, so a buyer's own slow transfers cannot take every slot from other
     *                               customers; null (tests and non-account callers) records no holder.
     */
    public function handle(array $target, ?int $snapshotDeadline = null, ?string $holder = null): PreparedDeliveryStream
    {
        ActivationPolicy::outsideTransactions();
        $policy = (new PaidGrantPolicy)->capture();
        if (($target['provenance'] ?? null) !== $policy['provenance'] || DIRECTORY_SEPARATOR !== '/') {
            throw new DeliveryException('unavailable');
        }
        $lease = $output = $reader = $reservation = null;
        $path = null;
        $owned = null;
        try {
            $this->target($target);
            $now = hrtime(true);
            $deadline = $snapshotDeadline === null ? $now + self::MAX_SECONDS * 1000000000
                : min($snapshotDeadline, $now + self::MAX_DEADLINE_SECONDS * 1000000000);
            $files = app(DeliveryAssetFiles::class);
            $root = $files->privateRoot();
            $directories = $this->directories($root, true);
            $spool = $root.'/delivery/paid-spool';
            if ($holder !== null && preg_match('/\A[a-f0-9]{64}\z/D', $holder) !== 1) {
                throw new \UnexpectedValueException;
            }
            [$lease, $path, $reservation] = $this->admit($spool, $target['size_bytes'], $holder);
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
            $this->copyTarget($target, $output, $deadline);
            $this->within($deadline);
            if (! $this->flush($output)) {
                throw new \UnexpectedValueException;
            }
            $this->sameFile($path, fstat($output), 0600, $target['size_bytes']);
            if (! @chmod($path, 0400)) {
                throw new \UnexpectedValueException;
            }
            $sealed = fstat($output);
            $this->sameFile($path, $sealed, 0400, $target['size_bytes']);
            // Only the spool is reopened, for a read-only descriptor. The purchased object is never reopened after checking.
            $reader = @fopen($path, 'rb');
            if (! is_resource($reader) || $this->identity(fstat($reader)) !== $this->identity($sealed)) {
                throw new \UnexpectedValueException;
            }
            fclose($output);
            $output = null;
            $this->readBack($reader, $target, $deadline);
            $this->sameFile($path, fstat($reader), 0400, $target['size_bytes']);
            if ($root !== $files->privateRoot() || $directories !== $this->directories($root, false)) {
                throw new \UnexpectedValueException;
            }
            $this->within($deadline);
            if (! $this->unlinkSpool($path)) {
                throw new \UnexpectedValueException;
            }
            $path = null;
            // The finished snapshot is now part of what the disk reports, so its reservation is released.
            $this->reserve($reservation, 0);
            $detached = fstat($reader);
            if ($detached['nlink'] !== 0 || $detached['size'] !== $target['size_bytes'] || ($detached['mode'] & 07777) !== 0400
                || $detached['ino'] !== $sealed['ino'] || $detached['dev'] !== $sealed['dev'] || fseek($reader, 0) !== 0) {
                throw new \UnexpectedValueException;
            }
            $prepared = new PreparedDeliveryStream($reader, $target['sha256'], $target['size_bytes'], $lease);
            $reader = $lease = null;

            return $prepared;
        } catch (Throwable) {
            throw new DeliveryException('target_unavailable');
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

    private function target(array $target): void
    {
        $kind = $target['kind'] ?? null;
        $maximum = $kind === 'contract' ? PaidGrantFiles::MAX_BYTES : DeliveryAssetFiles::MAX_BYTES;
        if (! in_array($kind, ['contract', 'master_wav', 'download_mp3', 'stems_zip'], true)
            || ($target['disk'] ?? null) !== 'local' || ! is_int($target['size_bytes'] ?? null)
            || $target['size_bytes'] < 1 || $target['size_bytes'] > $maximum
            || ! is_string($target['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $target['sha256'])) {
            throw new \UnexpectedValueException;
        }
        if ($kind !== 'contract' && (($target['role'] ?? null) !== $kind
            || ! in_array($target['scan_scope'] ?? null, ['test-only', 'clamav'], true)
            || ($target['scan_scope'] === 'test-only' && ! app()->environment('testing')))) {
            throw new \UnexpectedValueException;
        }
    }

    protected function copyTarget(array $target, $destination, int $deadline): void
    {
        if ($target['kind'] !== 'contract') {
            app(DeliveryAssetFiles::class)->copyVerified($target, $destination, $deadline);

            return;
        }
        $bytes = app(PaidGrantFiles::class)->verify(['pdf_hash' => $target['sha256']] + $target);
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $this->within($deadline);
            $written = @fwrite($destination, substr($bytes, $offset, min(1048576, $length - $offset)));
            if (! is_int($written) || $written < 1) {
                throw new \UnexpectedValueException;
            }
            $offset += $written;
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

    private function readBack($input, array $target, int $deadline): void
    {
        $hash = hash_init('sha256');
        $bytes = 0;
        while (! feof($input)) {
            $this->within($deadline);
            $chunk = @fread($input, min(1048576, $target['size_bytes'] - $bytes + 1));
            if (! is_string($chunk) || ($chunk === '' && ! feof($input))) {
                throw new \UnexpectedValueException;
            }
            $bytes += strlen($chunk);
            if ($bytes > $target['size_bytes']) {
                throw new \UnexpectedValueException;
            }
            hash_update($hash, $chunk);
        }
        if ($bytes !== $target['size_bytes'] || ! hash_equals($target['sha256'], hash_final($hash))) {
            throw new \UnexpectedValueException;
        }
    }

    /**
     * Slot and disk admission under one short global lock, so concurrent snapshots cannot each pass the free-space
     * probe alone: the bytes reserved by every other held slot are subtracted before this size is admitted.
     *
     * @return array{0:resource,1:string,2:resource} the slot lease, its snapshot path and its reservation handle
     */
    private function admit(string $spool, int $bytes, ?string $holder): array
    {
        $gate = $this->lease($spool.'/admission.lock');
        try {
            if (! @flock($gate, LOCK_EX)) {
                throw new \UnexpectedValueException;
            }
            [$lease, $snapshot, $number] = $this->slot($spool);
            $reservation = null;
            try {
                // One held slot per buyer: a held slot of the same holder refuses this one before anything is reserved.
                if ($holder !== null && in_array($holder, $this->holders($spool, $number), true)) {
                    throw new \UnexpectedValueException;
                }
                // Written sizes are read before free space: a byte another holder writes in between is then still counted
                // as pending and also missing from free space (conservative), never counted as written but still free.
                $pending = $this->pending($spool, $number);
                $space = $this->freeBytes($spool);
                if (! is_numeric($space) || ! is_finite((float) $space) || $space - $pending < $bytes + self::RESERVE_BYTES) {
                    throw new \UnexpectedValueException;
                }
                $mark = $this->reservation($spool.'/slot-'.$number.'.holder', 64);
                try {
                    $this->write($mark, $holder ?? str_repeat('0', 64));
                } finally {
                    fclose($mark);
                }
                $reservation = $this->reservation($spool.'/slot-'.$number.'.reserve');
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
     * A fixed path per locked slot prevents accumulating crash leftovers across fresh UUID names. A slot whose snapshot
     * exists, or whose reservation (20-byte) or holder (64-byte) sidecar is anything but a regular single-link 0600 file of
     * that size owned by this process user, is skipped and never repaired or deleted.
     *
     * @return array{0:resource,1:string,2:int}
     */
    private function slot(string $spool): array
    {
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            $candidate = $this->lease($spool.'/slot-'.$slot.'.lock');
            if (! @flock($candidate, LOCK_EX | LOCK_NB)) {
                fclose($candidate);

                continue;
            }
            $snapshot = $spool.'/slot-'.$slot.'.snapshot';
            clearstatcache(true, $snapshot);
            $unusable = @lstat($snapshot) !== false;
            foreach (['reserve' => 20, 'holder' => 64] as $suffix => $size) {
                $sidecar = $spool.'/slot-'.$slot.'.'.$suffix;
                clearstatcache(true, $sidecar);
                $stat = @lstat($sidecar);
                $unusable = $unusable || ($stat !== false && (($stat['mode'] & 0170000) !== 0100000 || ($stat['mode'] & 07777) !== 0600
                    || $stat['nlink'] !== 1 || $stat['uid'] !== $owner || $stat['size'] !== $size));
            }
            if ($unusable) {
                fclose($candidate);

                continue;
            }

            return [$candidate, $snapshot, $slot];
        }

        throw new \UnexpectedValueException;
    }

    /**
     * Bytes still to be written by every other slot that is held right now: its reservation less what its snapshot
     * already holds, because written bytes are already reflected in the free-space probe. A snapshot that is not a
     * regular single-link file, or is larger than its reservation, counts as nothing written (the full reservation).
     */
    private function pending(string $spool, int $own): int
    {
        $total = 0;
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            if ($slot === $own) {
                continue;
            }
            $probe = $this->lease($spool.'/slot-'.$slot.'.lock');
            try {
                if (@flock($probe, LOCK_EX | LOCK_NB)) {
                    @flock($probe, LOCK_UN);

                    continue;
                }
            } finally {
                fclose($probe);
            }
            $reserved = $this->reserved($spool.'/slot-'.$slot.'.reserve');
            $snapshot = $spool.'/slot-'.$slot.'.snapshot';
            clearstatcache(true, $snapshot);
            $stat = @lstat($snapshot);
            $written = is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && $stat['nlink'] === 1 && $stat['size'] <= $reserved ? $stat['size'] : 0;
            $total += $reserved - $written;
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

    /** Holders recorded by every other slot that is held right now. A held slot's unreadable holder fails closed. */
    private function holders(string $spool, int $own): array
    {
        $holders = [];
        for ($slot = 0; $slot < self::SLOTS; $slot++) {
            if ($slot === $own) {
                continue;
            }
            $probe = $this->lease($spool.'/slot-'.$slot.'.lock');
            try {
                if (@flock($probe, LOCK_EX | LOCK_NB)) {
                    @flock($probe, LOCK_UN);

                    continue;
                }
            } finally {
                fclose($probe);
            }
            $path = $spool.'/slot-'.$slot.'.holder';
            clearstatcache(true, $path);
            if (@lstat($path) === false) {
                continue;
            }
            $handle = @fopen($path, 'rb');
            if (! is_resource($handle)) {
                throw new \UnexpectedValueException;
            }
            try {
                $this->sameFile($path, fstat($handle), 0600, 64);
                $value = fread($handle, 64);
                if (! is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
                    throw new \UnexpectedValueException;
                }
                $holders[] = $value;
            } finally {
                fclose($handle);
            }
        }

        return $holders;
    }

    /**
     * A slot's fixed-size sidecar (the 20-byte reservation or the 64-byte holder), written only under both the slot lock
     * and the admission lock. A missing sidecar is created under a fresh name and renamed into place, so a crash never
     * leaves a partial file at the fixed path.
     */
    private function reservation(string $path, int $length = 20)
    {
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
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
            $written = @fwrite($created, str_repeat('0', $length)) === $length && @fflush($created);
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
            $this->sameFile($path, fstat($handle), 0600, $length);

            return $handle;
        } catch (Throwable $error) {
            fclose($handle);
            throw $error;
        }
    }

    private function write($handle, string $value): void
    {
        if (fseek($handle, 0) !== 0 || @fwrite($handle, $value) !== strlen($value) || ! @fflush($handle)) {
            throw new \UnexpectedValueException;
        }
    }

    private function reserve($handle, int $bytes): void
    {
        if (fseek($handle, 0) !== 0 || @fwrite($handle, sprintf('%020d', $bytes)) !== 20 || ! @fflush($handle)) {
            throw new \UnexpectedValueException;
        }
    }

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

    private function directories(string $root, bool $create): array
    {
        $result = [];
        $path = $root;
        foreach (['delivery', 'paid-spool'] as $component) {
            $path .= '/'.$component;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if ($stat === false && $create) {
                if (! @mkdir($path, 0700) && ! is_dir($path)) {
                    throw new \UnexpectedValueException;
                }
                clearstatcache(true, $path);
                $stat = @lstat($path);
            }
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 07777) !== 0700
                || realpath($path) !== $path) {
                throw new \UnexpectedValueException;
            }
            $result[$path] = array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']));
        }

        return $result;
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
        if (hrtime(true) > $deadline) {
            throw new \UnexpectedValueException;
        }
    }
}
