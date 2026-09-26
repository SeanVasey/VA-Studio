<?php

namespace App\Domain\Delivery;

use App\Domain\Contracts\ContractFiles;
use Throwable;

/** Test-only POSIX snapshot adapter. Three held leases bound named plus unlinked snapshots to at most 3 GiB. */
class PrepareTestDeliveryStream
{
    public const SLOTS = 3;
    public const RESERVE_BYTES = 16777216;
    public const MAX_SECONDS = 60;

    public function handle(array $target): PreparedDeliveryStream
    {
        ActivationPolicy::outsideTransactions();
        if (! app()->environment('local', 'testing') || DIRECTORY_SEPARATOR !== '/') { throw new DeliveryException('unavailable'); }
        $lease = $output = $reader = null; $path = null; $owned = null;
        try {
            $this->target($target);
            $deadline = hrtime(true) + self::MAX_SECONDS * 1000000000;
            $files = app(DeliveryAssetFiles::class); $root = $files->privateRoot();
            $directories = $this->directories($root, true); $spool = $root.'/delivery/spool';
            // A fixed path per locked slot prevents accumulating crash leftovers across fresh UUID names.
            for ($slot = 0; $slot < self::SLOTS; $slot++) {
                $candidate = $this->lease($spool.'/slot-'.$slot.'.lock');
                if (! @flock($candidate, LOCK_EX | LOCK_NB)) { fclose($candidate); continue; }
                $snapshot = $spool.'/slot-'.$slot.'.snapshot'; clearstatcache(true, $snapshot);
                if (@lstat($snapshot) !== false) { fclose($candidate); continue; } // Never repair or delete a pre-existing crash residue.
                $lease = $candidate; $path = $snapshot; break;
            }
            if (! is_resource($lease)) { throw new \UnexpectedValueException; }
            $space = $this->freeBytes($spool);
            if (! is_numeric($space) || ! is_finite((float) $space) || $space < $target['size_bytes'] + self::RESERVE_BYTES) { throw new \UnexpectedValueException; }
            $mask = umask(0077);
            try { $output = @fopen($path, 'x+b'); } finally { umask($mask); }
            if (! is_resource($output)) { throw new \UnexpectedValueException; }
            $owned = fstat($output); $this->sameFile($path, $owned, 0600, 0);
            $this->copyTarget($target, $output, $deadline);
            $this->within($deadline);
            if (! $this->flush($output)) { throw new \UnexpectedValueException; }
            $this->sameFile($path, fstat($output), 0600, $target['size_bytes']);
            if (! @chmod($path, 0400)) { throw new \UnexpectedValueException; }
            $sealed = fstat($output); $this->sameFile($path, $sealed, 0400, $target['size_bytes']);
            // Only the spool is reopened, for a read-only descriptor. The purchased object is never reopened after checking.
            $reader = @fopen($path, 'rb');
            if (! is_resource($reader) || $this->identity(fstat($reader)) !== $this->identity($sealed)) { throw new \UnexpectedValueException; }
            fclose($output); $output = null;
            $this->readBack($reader, $target, $deadline);
            $this->sameFile($path, fstat($reader), 0400, $target['size_bytes']);
            if ($root !== $files->privateRoot() || $directories !== $this->directories($root, false)) { throw new \UnexpectedValueException; }
            $this->within($deadline);
            if (! $this->unlinkSpool($path)) { throw new \UnexpectedValueException; }
            $path = null; $detached = fstat($reader);
            if ($detached['nlink'] !== 0 || $detached['size'] !== $target['size_bytes'] || ($detached['mode'] & 07777) !== 0400
                || $detached['ino'] !== $sealed['ino'] || $detached['dev'] !== $sealed['dev'] || fseek($reader, 0) !== 0) { throw new \UnexpectedValueException; }
            $prepared = new PreparedDeliveryStream($reader, $target['sha256'], $target['size_bytes'], $lease);
            $reader = $lease = null;
            return $prepared;
        } catch (Throwable) {
            throw new DeliveryException('target_unavailable');
        } finally {
            if ($path !== null && is_array($owned)) {
                clearstatcache(true, $path); $current = @lstat($path);
                // Remove only the snapshot this call exclusively created, never an unexpected replacement.
                if (is_array($current) && $current['dev'] === $owned['dev'] && $current['ino'] === $owned['ino']
                    && ($current['mode'] & 0170000) === 0100000 && $current['nlink'] === 1) { @unlink($path); }
            }
            foreach ([$output, $reader, $lease] as $handle) { if (is_resource($handle)) { fclose($handle); } }
        }
    }

    private function target(array $target): void
    {
        $kind = $target['kind'] ?? null;
        $maximum = $kind === 'contract' ? ContractFiles::MAX_BYTES : DeliveryAssetFiles::MAX_BYTES;
        if (! in_array($kind, ['contract', 'master_wav', 'download_mp3', 'stems_zip'], true)
            || ($target['disk'] ?? null) !== 'local' || ! is_int($target['size_bytes'] ?? null)
            || $target['size_bytes'] < 1 || $target['size_bytes'] > $maximum
            || ! is_string($target['sha256'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $target['sha256'])) { throw new \UnexpectedValueException; }
        if ($kind !== 'contract' && (($target['role'] ?? null) !== $kind
            || ! in_array($target['scan_scope'] ?? null, ['test-only', 'clamav'], true)
            || ($target['scan_scope'] === 'test-only' && ! app()->environment('testing')))) { throw new \UnexpectedValueException; }
    }

    protected function copyTarget(array $target, $destination, int $deadline): void
    {
        if ($target['kind'] !== 'contract') {
            app(DeliveryAssetFiles::class)->copyVerified($target, $destination, $deadline);
            return;
        }
        $bytes = app(ContractFiles::class)->verify(['pdf_hash' => $target['sha256']] + $target);
        $offset = 0; $length = strlen($bytes);
        while ($offset < $length) {
            $this->within($deadline);
            $written = @fwrite($destination, substr($bytes, $offset, min(1048576, $length - $offset)));
            if (! is_int($written) || $written < 1) { throw new \UnexpectedValueException; }
            $offset += $written;
        }
    }

    protected function freeBytes(string $directory): float|false { return @disk_free_space($directory); }
    protected function flush($handle): bool { return function_exists('fsync') && @fflush($handle) && @fsync($handle); }
    protected function unlinkSpool(string $path): bool { return @unlink($path); }

    private function readBack($input, array $target, int $deadline): void
    {
        $hash = hash_init('sha256'); $bytes = 0;
        while (! feof($input)) {
            $this->within($deadline);
            $chunk = @fread($input, min(1048576, $target['size_bytes'] - $bytes + 1));
            if (! is_string($chunk) || ($chunk === '' && ! feof($input))) { throw new \UnexpectedValueException; }
            $bytes += strlen($chunk);
            if ($bytes > $target['size_bytes']) { throw new \UnexpectedValueException; }
            hash_update($hash, $chunk);
        }
        if ($bytes !== $target['size_bytes'] || ! hash_equals($target['sha256'], hash_final($hash))) { throw new \UnexpectedValueException; }
    }

    private function lease(string $path)
    {
        clearstatcache(true, $path); $before = @lstat($path);
        if ($before === false) {
            $mask = umask(0077);
            try { $created = @fopen($path, 'x+b'); } finally { umask($mask); }
            if (is_resource($created)) {
                try {
                    $this->sameFile($path, fstat($created), 0600, 0);
                    if (! @chmod($path, 0400)) { throw new \UnexpectedValueException; }
                } finally { fclose($created); }
            }
            clearstatcache(true, $path); $before = @lstat($path);
        }
        $this->sameFile($path, $before, 0400, 0);
        $input = @fopen($path, 'rb');
        if (! is_resource($input)) { throw new \UnexpectedValueException; }
        try {
            if ($this->identity($before) !== $this->identity(fstat($input))) { throw new \UnexpectedValueException; }
            $this->sameFile($path, fstat($input), 0400, 0);
            return $input;
        } catch (Throwable $error) { fclose($input); throw $error; }
    }

    private function directories(string $root, bool $create): array
    {
        $result = []; $path = $root;
        foreach (['delivery', 'spool'] as $component) {
            $path .= '/'.$component; clearstatcache(true, $path); $stat = @lstat($path);
            if ($stat === false && $create) {
                if (! @mkdir($path, 0700) && ! is_dir($path)) { throw new \UnexpectedValueException; }
                clearstatcache(true, $path); $stat = @lstat($path);
            }
            if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 07777) !== 0700
                || realpath($path) !== $path) { throw new \UnexpectedValueException; }
            $result[$path] = array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid', 'gid']));
        }
        return $result;
    }

    private function sameFile(string $path, array|false $opened, int $mode, int $bytes): void
    {
        clearstatcache(true, $path); $current = @lstat($path);
        if (! is_array($opened) || ! is_array($current) || ($current['mode'] & 0170000) !== 0100000
            || ($opened['mode'] & 0170000) !== 0100000 || ($current['mode'] & 07777) !== $mode
            || ($opened['mode'] & 07777) !== $mode || $current['nlink'] !== 1 || $opened['nlink'] !== 1
            || $current['size'] !== $bytes || $opened['size'] !== $bytes || realpath($path) !== $path
            || $this->identity($opened) !== $this->identity($current)) { throw new \UnexpectedValueException; }
    }

    private function identity(array|false $stat): array
    {
        return is_array($stat) ? array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'nlink', 'uid', 'gid', 'mtime', 'ctime'])) : [];
    }

    private function within(int $deadline): void
    {
        if (hrtime(true) > $deadline) { throw new \UnexpectedValueException; }
    }
}
