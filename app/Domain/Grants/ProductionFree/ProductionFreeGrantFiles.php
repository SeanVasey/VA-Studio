<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Contracts\ContractIo;
use App\Domain\Contracts\RenderedContract;
use App\Domain\Delivery\DeliveryAssetFiles;
use Throwable;

/**
 * Write-once private originals at `contracts/production-free-v1/{originUUID}/{claimUUID}/original.pdf`.
 * `link()` of a fresh random name onto `original.pdf` is the inter-worker arbitration point; nothing here overwrites,
 * replaces or deletes a published file (only the call's own random temporary name is removed).
 * The root is the validated private local delivery root (never public, served or linked).
 */
final class ProductionFreeGrantFiles
{
    public const MAX_BYTES = 16777216;

    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public static function path(string $originId, string $claimId): string
    {
        return 'contracts/production-free-v1/'.$originId.'/'.$claimId.'/original.pdf';
    }

    /** @return array{disk:string,storage_path:string,pdf_hash:string,size_bytes:int,page_count:int,profile_hash:string} */
    public function store(string $originId, string $claimId, RenderedContract $rendered): array
    {
        ContractIo::outsideTransactions();
        $output = $temporary = $owned = null;
        try {
            $record = ['disk' => 'local', 'storage_path' => self::path($originId, $claimId), 'pdf_hash' => $rendered->sha256,
                'size_bytes' => $rendered->sizeBytes, 'page_count' => $rendered->pageCount, 'profile_hash' => $rendered->profileHash];
            $this->validateRecord($record);
            $this->validateBytes($rendered->pdfBytes, $record);
            $root = $this->root();
            $directory = $root;
            foreach (explode('/', dirname($record['storage_path'])) as $component) {
                $directory .= '/'.$component;
                $this->directory($directory, true);
            }
            $path = $directory.'/original.pdf';
            // `fopen('x')` on `original.pdf` would follow a planted dangling symlink and create its target outside the
            // private root. Write a fresh random name instead, then `link()` it into place: `link()` never follows
            // a destination symlink and never replaces an existing entry, so it is the write-once arbiter.
            $temporary = $directory.'/.original-'.bin2hex(random_bytes(16)).'.tmp';
            $mask = umask(0077);
            try {
                $output = @fopen($temporary, 'x+b');
            } finally {
                umask($mask);
            }
            if (! is_resource($output)) {
                throw new \UnexpectedValueException;
            }
            $owned = fstat($output);
            $this->sameFile($temporary, $owned, $root, false);
            $offset = 0;
            while ($offset < strlen($rendered->pdfBytes)) {
                $written = @fwrite($output, substr($rendered->pdfBytes, $offset, 1048576));
                if (! is_int($written) || $written < 1) {
                    throw new \UnexpectedValueException;
                }
                $offset += $written;
            }
            if (! @fflush($output) || (function_exists('fsync') && ! @fsync($output)) || fseek($output, 0) !== 0) {
                throw new \UnexpectedValueException;
            }
            $bytes = stream_get_contents($output, self::MAX_BYTES + 1);
            if (! is_string($bytes)) {
                throw new \UnexpectedValueException;
            }
            $this->validateBytes($bytes, $record);
            if (! @chmod($temporary, 0400)) {
                throw new \UnexpectedValueException;
            }
            $this->sameFile($temporary, fstat($output), $root, true);
            if (! @link($temporary, $path) || ! @unlink($temporary)) {
                throw new \UnexpectedValueException;
            }
            $temporary = null;
            fclose($output);
            $output = null;
            $this->verify($record);

            return $record;
        } catch (Throwable) {
            // A failed claim may leave an unreferenced claim path. It is never deleted or reused.
            throw new ProductionFreeGrantException('storage_failed');
        } finally {
            if (is_string($temporary) && is_array($owned)) {
                clearstatcache(true, $temporary);
                $current = @lstat($temporary);
                // Remove only the random name this call exclusively created, never an unexpected replacement.
                if (is_array($current) && $current['dev'] === $owned['dev'] && $current['ino'] === $owned['ino'] && ($current['mode'] & 0170000) === 0100000) {
                    @unlink($temporary);
                }
            }
            if (is_resource($output)) {
                fclose($output);
            }
        }
    }

    /** Read the exact retained original by its recorded hash; any drift is refused, never repaired. */
    public function verify(array $record): string
    {
        ContractIo::outsideTransactions();
        $input = null;
        try {
            $this->validateRecord($record);
            $root = $this->root();
            $directory = $root;
            foreach (explode('/', dirname($record['storage_path'])) as $component) {
                $directory .= '/'.$component;
                $this->directory($directory, false);
            }
            $path = $directory.'/original.pdf';
            clearstatcache(true, $path);
            $before = @lstat($path);
            $this->sameFile($path, $before, $root, true);
            $input = @fopen($path, 'rb');
            if (! is_resource($input)) {
                throw new \UnexpectedValueException;
            }
            $opened = fstat($input);
            if ($before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev'] || $opened['size'] !== $record['size_bytes']) {
                throw new \UnexpectedValueException;
            }
            $bytes = stream_get_contents($input, self::MAX_BYTES + 1);
            if (! is_string($bytes)) {
                throw new \UnexpectedValueException;
            }
            $this->validateBytes($bytes, $record);
            $this->sameFile($path, fstat($input), $root, true);

            return $bytes;
        } catch (Throwable) {
            throw new ProductionFreeGrantException('original_unavailable');
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    /** Private spool directory for hash-verified delivery snapshots (unlinked after open). */
    public function spoolDirectory(): string
    {
        $directory = $this->root();
        foreach (['delivery', 'production-free-spool'] as $component) {
            $directory .= '/'.$component;
            $this->directory($directory, true);
        }

        return $directory;
    }

    private function root(): string
    {
        return (new DeliveryAssetFiles)->privateRoot();
    }

    private function validateRecord(array $record): void
    {
        $keys = array_keys($record);
        sort($keys, SORT_STRING);
        if ($keys !== ['disk', 'page_count', 'pdf_hash', 'profile_hash', 'size_bytes', 'storage_path'] || $record['disk'] !== 'local'
            || ! is_string($record['storage_path'])
            || ! preg_match('~\Acontracts/production-free-v1/'.self::UUID.'/'.self::UUID.'/original\.pdf\z~D', $record['storage_path'])
            || ! is_int($record['size_bytes']) || $record['size_bytes'] < 32 || $record['size_bytes'] > self::MAX_BYTES
            || ! is_int($record['page_count']) || $record['page_count'] < 1 || $record['page_count'] > 100
            || ! is_string($record['pdf_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $record['pdf_hash'])
            || ! is_string($record['profile_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $record['profile_hash'])) {
            throw new \UnexpectedValueException;
        }
    }

    private function validateBytes(string $bytes, array $record): void
    {
        if (strlen($bytes) !== $record['size_bytes'] || ! hash_equals($record['pdf_hash'], hash('sha256', $bytes))
            || ! preg_match('/\A%PDF-1\.[0-7](?:\r\n|\n|\r)/D', $bytes) || ! str_ends_with(rtrim($bytes, "\r\n\t "), '%%EOF')) {
            throw new \UnexpectedValueException;
        }
    }

    private function directory(string $path, bool $create): void
    {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat === false && $create) {
            if (! @mkdir($path, 0700) && ! is_dir($path)) {
                throw new \UnexpectedValueException;
            }
            clearstatcache(true, $path);
            $stat = @lstat($path);
        }
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000 || ($stat['mode'] & 0777) !== 0700) {
            throw new \UnexpectedValueException;
        }
    }

    private function sameFile(string $path, array|false $opened, string $root, bool $sealed): void
    {
        clearstatcache(true, $path);
        $current = @lstat($path);
        $real = realpath($path);
        if (! is_array($opened) || ! is_array($current) || ($opened['mode'] & 0170000) !== 0100000
            || ($current['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || $current['nlink'] !== 1
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino'] || $real !== $path || ! str_starts_with($real, $root.'/')
            || ($sealed && (($current['mode'] & 0777) !== 0400 || ($opened['mode'] & 0777) !== 0400))) {
            throw new \UnexpectedValueException;
        }
    }
}
