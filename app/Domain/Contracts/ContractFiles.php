<?php

namespace App\Domain\Contracts;

use Illuminate\Support\Facades\Storage;
use Throwable;

/** Private append-only originals. No record publication, overwrite, replacement or cleanup. */
final class ContractFiles
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    public const MAX_BYTES = 16777216;

    /** @return array{disk:string,storage_path:string,pdf_hash:string,size_bytes:int,page_count:int,profile_hash:string} */
    public function store(string $requestId, string $claimId, RenderedContract $rendered): array
    {
        ContractIo::outsideTransactions();
        $output = null;
        try {
            if (! preg_match('~\A'.self::UUID.'\z~D', $requestId) || ! preg_match('~\A'.self::UUID.'\z~D', $claimId)) {
                throw new \UnexpectedValueException;
            }
            $record = ['disk' => 'local', 'storage_path' => "contracts/test/{$requestId}/{$claimId}/original.pdf",
                'pdf_hash' => $rendered->sha256, 'size_bytes' => $rendered->sizeBytes,
                'page_count' => $rendered->pageCount, 'profile_hash' => $rendered->profileHash];
            $this->validateRecord($record);
            $this->validateBytes($rendered->pdfBytes, $record);
            $root = $this->root(true);
            $directory = $root;
            foreach (explode('/', dirname($record['storage_path'])) as $component) {
                $directory .= '/'.$component;
                $this->directory($directory, true, true);
            }
            $path = $directory.'/original.pdf';
            // The x mode is the inter-worker arbitration point; a duplicate path is never replaced.
            $mask = umask(0077);
            try { $output = @fopen($path, 'x+b'); } finally { umask($mask); }
            if (! is_resource($output)) { throw new \UnexpectedValueException; }
            $opened = fstat($output);
            $this->sameFile($path, $opened, $root, false);
            $length = strlen($rendered->pdfBytes); $offset = 0;
            while ($offset < $length) {
                $written = @fwrite($output, substr($rendered->pdfBytes, $offset, 1048576));
                if (! is_int($written) || $written < 1) { throw new \UnexpectedValueException; }
                $offset += $written;
            }
            if (! @fflush($output) || (function_exists('fsync') && ! @fsync($output))) { throw new \UnexpectedValueException; }
            if (fseek($output, 0) !== 0) { throw new \UnexpectedValueException; }
            $bytes = stream_get_contents($output, self::MAX_BYTES + 1);
            if (! is_string($bytes)) { throw new \UnexpectedValueException; }
            $this->validateBytes($bytes, $record);
            $this->sameFile($path, fstat($output), $root, false);
            if (! @chmod($path, 0400)) { throw new \UnexpectedValueException; }
            $this->sameFile($path, fstat($output), $root, true);
            fclose($output); $output = null;
            // Verify from a new descriptor before a caller may publish the immutable record.
            $this->verify($record);

            return $record;
        } catch (Throwable) {
            // Failed writes may leave unreferenced claim paths. Never delete or reuse an original.
            throw new ContractIssuanceException('storage_failed');
        } finally {
            if (is_resource($output)) { fclose($output); }
        }
    }

    /** Read only the retained exact original; callers authorize and validate its issuance evidence separately. */
    public function verify(array|object $record): string
    {
        ContractIo::outsideTransactions();
        $input = null;
        try {
            $record = is_array($record) ? $record : [
                'disk' => $record->disk, 'storage_path' => $record->storage_path, 'pdf_hash' => $record->pdf_hash,
                'size_bytes' => $record->size_bytes, 'page_count' => $record->page_count, 'profile_hash' => $record->profile_hash,
            ];
            $this->validateRecord($record);
            $root = $this->root(false);
            $directory = $root;
            foreach (explode('/', dirname($record['storage_path'])) as $component) {
                $directory .= '/'.$component;
                $this->directory($directory, false, true);
            }
            $path = $directory.'/original.pdf';
            clearstatcache(true, $path); $before = @lstat($path);
            $this->sameFile($path, $before, $root, true);
            $input = @fopen($path, 'rb');
            if (! is_resource($input)) { throw new \UnexpectedValueException; }
            $opened = fstat($input);
            if ($before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev'] || $opened['size'] !== $record['size_bytes']) {
                throw new \UnexpectedValueException;
            }
            $this->sameFile($path, $opened, $root, true);
            $bytes = stream_get_contents($input, self::MAX_BYTES + 1);
            if (! is_string($bytes)) { throw new \UnexpectedValueException; }
            $this->validateBytes($bytes, $record);
            $this->sameFile($path, fstat($input), $root, true);

            return $bytes;
        } catch (Throwable) {
            throw new ContractIssuanceException('original_unavailable');
        } finally {
            if (is_resource($input)) { fclose($input); }
        }
    }

    private function validateRecord(array $record): void
    {
        if (($record['disk'] ?? null) !== 'local' || ! is_string($record['storage_path'] ?? null)
            || ! preg_match('~\Acontracts/test/'.self::UUID.'/'.self::UUID.'/original\.pdf\z~D', $record['storage_path'])
            || ! is_int($record['size_bytes'] ?? null) || $record['size_bytes'] < 32 || $record['size_bytes'] > self::MAX_BYTES
            || ! is_int($record['page_count'] ?? null) || $record['page_count'] < 1 || $record['page_count'] > 100
            || ! is_string($record['pdf_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $record['pdf_hash'])
            || ! is_string($record['profile_hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/D', $record['profile_hash'])) {
            throw new \UnexpectedValueException;
        }
    }

    private function validateBytes(string $bytes, array $record): void
    {
        if (strlen($bytes) !== $record['size_bytes'] || ! hash_equals($record['pdf_hash'], hash('sha256', $bytes))
            || ! preg_match('/\A%PDF-1\.[0-7](?:\r\n|\n|\r)/D', $bytes)
            || ! str_ends_with(rtrim($bytes, "\r\n\t "), '%%EOF')) {
            throw new \UnexpectedValueException;
        }
    }

    private function root(bool $create): string
    {
        $disk = config('filesystems.disks.local');
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 'local' || ($disk['serve'] ?? false) !== false
            || ($disk['visibility'] ?? 'private') !== 'private') { throw new \UnexpectedValueException; }
        $path = rtrim(Storage::disk('local')->path(''), '/');
        if (! str_starts_with($path, '/') || str_contains($path, '//')) { throw new \UnexpectedValueException; }
        $current = '';
        foreach (explode('/', ltrim($path, '/')) as $component) {
            if ($component === '' || $component === '.' || $component === '..') { throw new \UnexpectedValueException; }
            $current .= '/'.$component; $this->directory($current, $create, false);
        }
        $real = realpath($path);
        if ($real !== $path || ((@fileperms($path) ?: 0) & 0022) !== 0) { throw new \UnexpectedValueException; }
        $publicRoots = [public_path(), storage_path('app/public')];
        foreach ((array) config('filesystems.disks', []) as $other) {
            if (is_array($other) && ($other['driver'] ?? null) === 'local'
                && (($other['serve'] ?? false) === true || ($other['visibility'] ?? null) === 'public')) {
                $publicRoots[] = $other['root'] ?? '';
            }
        }
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            if (str_starts_with($link, rtrim(public_path(), '/').'/')) { $publicRoots[] = $target; }
        }
        foreach ($publicRoots as $public) {
            if (! is_string($public) || $public === '') { continue; }
            $public = realpath($public) ?: rtrim($public, '/');
            // A served subtree inside the private root can expose future contract claim paths too.
            if ($real === $public || str_starts_with($real, $public.'/') || str_starts_with($public, $real.'/')) {
                throw new \UnexpectedValueException;
            }
        }

        return $real;
    }

    private function directory(string $path, bool $create, bool $private): void
    {
        clearstatcache(true, $path); $stat = @lstat($path);
        if ($stat === false && $create) {
            if (! @mkdir($path, 0700) && ! is_dir($path)) { throw new \UnexpectedValueException; }
            clearstatcache(true, $path); $stat = @lstat($path);
        }
        if (! is_array($stat) || ($stat['mode'] & 0170000) !== 0040000
            || ($private && ($stat['mode'] & 0777) !== 0700)) { throw new \UnexpectedValueException; }
    }

    private function sameFile(string $path, array|false $opened, string $root, bool $sealed): void
    {
        clearstatcache(true, $path); $current = @lstat($path); $real = realpath($path);
        if (! is_array($opened) || ! is_array($current) || ($opened['mode'] & 0170000) !== 0100000
            || ($current['mode'] & 0170000) !== 0100000 || $opened['nlink'] !== 1 || $current['nlink'] !== 1
            || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']
            || $real !== $path || ! str_starts_with($real, $root.'/')
            || ($sealed && (($current['mode'] & 0777) !== 0400 || ($opened['mode'] & 0777) !== 0400))) {
            throw new \UnexpectedValueException;
        }
    }
}
