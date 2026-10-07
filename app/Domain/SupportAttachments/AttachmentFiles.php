<?php

namespace App\Domain\SupportAttachments;

use App\Domain\Delivery\DeliveryAssetFiles;
use Throwable;

/** Separate support namespace. No MediaAsset, entitlement, public disk or client-supplied path. */
class AttachmentFiles
{
    private const UUID = '/\A[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\z/D';

    public function inspect(string $trustedUploadPath, int $maxBytes): array
    {
        AttachmentException::require(DBOutside::check());
        clearstatcache(true, $trustedUploadPath);
        $before = @lstat($trustedUploadPath);
        AttachmentException::require(is_array($before) && ($before['mode'] & 0170000) === 0100000 && $before['nlink'] === 1
            && $before['size'] >= 1 && $before['size'] <= $maxBytes && ! is_link($trustedUploadPath), 422, 'file_type');
        $input = @fopen($trustedUploadPath, 'rb');
        try {
            AttachmentException::require(is_resource($input) && $this->identity(fstat($input)) === $this->identity($before));
            $content = stream_get_contents($input, $maxBytes + 1);
            clearstatcache(true, $trustedUploadPath);
            AttachmentException::require(is_string($content) && strlen($content) === $before['size'] && strlen($content) <= $maxBytes
                && $this->identity(fstat($input)) === $this->identity($before) && $this->identity(@lstat($trustedUploadPath)) === $this->identity($before));

            return ['sizeBytes' => strlen($content), 'sha256' => hash('sha256', $content), 'mime' => $this->mime($content)];
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    public function preserve(string $id, string $trustedUploadPath, int $maxBytes, ?array $expected = null): array
    {
        $directory = $this->directory($id);
        $lock = $this->lock($directory.'/intake.lock');
        $input = null;
        $output = null;
        $path = $directory.'/original.bin';
        $new = false;
        try {
            clearstatcache(true, $trustedUploadPath);
            $before = @lstat($trustedUploadPath);
            AttachmentException::require(is_array($before) && ($before['mode'] & 0170000) === 0100000 && $before['nlink'] === 1
                && $before['size'] >= 1 && $before['size'] <= $maxBytes && ! is_link($trustedUploadPath), 422, 'file_type');
            $input = @fopen($trustedUploadPath, 'rb');
            AttachmentException::require(is_resource($input));
            AttachmentException::require($this->identity(fstat($input)) === $this->identity($before));
            if (! file_exists($path) && ! is_link($path)) {
                $output = @fopen($path, 'xb');
                AttachmentException::require(is_resource($output));
                $new = true;
                chmod($path, 0600);
            }
            $hash = hash_init('sha256');
            $bytes = 0;
            $content = '';
            $deadline = hrtime(true) + 30_000_000_000;
            while (! feof($input)) {
                AttachmentException::require(hrtime(true) <= $deadline);
                $chunk = $this->readChunk($input, min(1048576, $maxBytes - $bytes + 1));
                AttachmentException::require(is_string($chunk) && ($chunk !== '' || feof($input)));
                $bytes += strlen($chunk);
                AttachmentException::require($bytes <= $maxBytes, 413);
                $content .= $chunk;
                hash_update($hash, $chunk);
                if ($output !== null) {
                    $this->write($output, $chunk);
                }
            }
            AttachmentException::require($bytes === $before['size'] && $this->identity(fstat($input)) === $this->identity($before));
            clearstatcache(true, $trustedUploadPath);
            AttachmentException::require($this->identity(@lstat($trustedUploadPath)) === $this->identity($before));
            $digest = hash_final($hash);
            $mime = $this->mime($content);
            AttachmentException::require($expected === null || ($expected['sizeBytes'] === $bytes && $expected['sha256'] === $digest && $expected['mime'] === $mime), 409, 'changed_request');
            if ($output !== null) {
                AttachmentException::require(fflush($output) && fsync($output));
                fclose($output);
                $output = null;
                AttachmentException::require(chmod($path, 0400));
            }
            $entry = ['sizeBytes' => $bytes, 'sha256' => $digest, 'mime' => $mime];
            $this->verify($id, $entry);

            return $entry;
        } catch (Throwable $error) {
            // Only this definitely uncommitted, incomplete new copy can be removed. Sealed originals survive ambiguity.
            if ($new && is_resource($output)) {
                fclose($output);
                $output = null;
                @unlink($path);
            }
            throw $error instanceof AttachmentException ? $error : new AttachmentException;
        } finally {
            if (is_resource($input)) {
                fclose($input);
            } if (is_resource($output)) {
                fclose($output);
            } flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function verify(string $id, array $entry, $destination = null): void
    {
        $directory = $this->directory($id);
        $path = $directory.'/original.bin';
        $input = null;
        try {
            clearstatcache(true, $path);
            $before = @lstat($path);
            $this->same($path, $before, $entry['sizeBytes']);
            $input = @fopen($path, 'rb');
            AttachmentException::require(is_resource($input) && $this->identity(fstat($input)) === $this->identity($before));
            $hash = hash_init('sha256');
            $bytes = 0;
            $deadline = hrtime(true) + 30_000_000_000;
            while (! feof($input)) {
                AttachmentException::require(hrtime(true) <= $deadline);
                $chunk = $this->readChunk($input, min(1048576, $entry['sizeBytes'] - $bytes + 1));
                AttachmentException::require(is_string($chunk) && ($chunk !== '' || feof($input)));
                $bytes += strlen($chunk);
                AttachmentException::require($bytes <= $entry['sizeBytes']);
                hash_update($hash, $chunk);
                if ($destination !== null) {
                    $this->write($destination, $chunk);
                }
            }
            $this->same($path, fstat($input), $entry['sizeBytes']);
            AttachmentException::require($bytes === $entry['sizeBytes'] && hash_equals($entry['sha256'], hash_final($hash)));
            AttachmentException::require($this->identity(@lstat($path)) === $this->identity($before));
            $this->directory($id);
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
        }
    }

    /** Three exact 5MiB snapshots maximum per root, across PHP processes. Crash residue fails closed. */
    public function snapshot(string $id, array $entry): AttachmentSnapshot
    {
        $directory = $this->root().'/support-attachments/snapshots';
        $this->ensureDirectory($directory);
        $lease = null;
        for ($slot = 0; $slot < 3; $slot++) {
            $path = $directory.'/'.$slot.'.bin';
            try {
                $lease = $this->lock($directory.'/'.$slot.'.lock');
            } catch (AttachmentException) {
                continue;
            }
            if (file_exists($path) || is_link($path)) {
                flock($lease, LOCK_UN);
                fclose($lease);
                $lease = null;

                continue;
            }
            $output = @fopen($path, 'xb');
            if (! is_resource($output)) {
                flock($lease, LOCK_UN);
                fclose($lease);
                $lease = null;

                continue;
            }
            try {
                chmod($path, 0600);
                $this->verify($id, $entry, $output);
                AttachmentException::require(fflush($output) && fsync($output));
                fclose($output);
                $output = null;
                AttachmentException::require(chmod($path, 0400));

                return new AttachmentSnapshot($path, $lease, $entry);
            } catch (Throwable $error) {
                if (is_resource($output)) {
                    fclose($output);
                } @unlink($path);
                flock($lease, LOCK_UN);
                fclose($lease);
                throw $error;
            }
        }
        throw new AttachmentException(503, 'storage_busy');
    }

    /** Called only after a positively committed terminal tombstone. Exact old bytes are never replaced. */
    public function remove(string $id, array $entry): void
    {
        $directory = $this->directory($id);
        $path = $directory.'/original.bin';
        $lock = $this->lock($directory.'/intake.lock');
        try {
            if (file_exists($path) || is_link($path)) {
                $this->verify($id, $entry);
                AttachmentException::require(unlink($path));
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    protected function readChunk($input, int $bytes): string|false
    {
        return fread($input, $bytes);
    }

    private function root(): string
    {
        AttachmentException::require(DBOutside::check());
        try {
            return app(DeliveryAssetFiles::class)->privateRoot();
        } catch (Throwable) {
            throw new AttachmentException;
        }
    }

    private function directory(string $id): string
    {
        AttachmentException::require(preg_match(self::UUID, $id) === 1);
        $root = $this->root();
        foreach (['support-attachments', 'support-attachments/originals', 'support-attachments/originals/'.$id] as $relative) {
            $this->ensureDirectory($root.'/'.$relative);
        }

        return $root.'/support-attachments/originals/'.$id;
    }

    private function ensureDirectory(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            @mkdir($path, 0700);
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        AttachmentException::require(is_array($stat) && ($stat['mode'] & 0170000) === 0040000 && ($stat['mode'] & 07777) === 0700 && realpath($path) === $path);
    }

    private function lock(string $path)
    {
        $lock = @fopen($path, 'x+b');
        if (is_resource($lock)) {
            chmod($path, 0600);
        } else {
            $lock = @fopen($path, 'r+b');
        }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! (is_resource($lock) && is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 07777) === 0600
            && $stat['nlink'] === 1 && $this->identity($stat) === $this->identity(fstat($lock)) && realpath($path) === $path)) {
            if (is_resource($lock)) {
                fclose($lock);
            } throw new AttachmentException;
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new AttachmentException(503, 'storage_busy');
        }

        return $lock;
    }

    private function same(string $path, array|false $stat, int $bytes): void
    {
        AttachmentException::require(is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 07777) === 0400
            && $stat['nlink'] === 1 && $stat['size'] === $bytes && realpath($path) === $path);
    }

    private function identity(array|false $stat): array
    {
        return is_array($stat) ? array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'nlink', 'uid', 'gid', 'mtime', 'ctime'])) : [];
    }

    private function write($output, string $chunk): void
    {
        while ($chunk !== '') {
            $bytes = fwrite($output, $chunk);
            AttachmentException::require(is_int($bytes) && $bytes > 0);
            $chunk = substr($chunk, $bytes);
        }
    }

    private function mime(string $content): string
    {
        // No archive/executable format is admitted. Reject common conflicting signatures even behind a textual prefix.
        AttachmentException::require(! preg_match('/PK\x03\x04|\x1f\x8b|\x7fELF|MZ|Rar!|7z\xbc\xaf|ustar|\A#!|<\?php/i', $content), 422, 'file_type');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        AttachmentException::require(is_string($mime), 422, 'file_type');
        if ($mime === 'text/plain') {
            AttachmentException::require(mb_check_encoding($content, 'UTF-8') && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $content), 422, 'file_type');
        }
        if ($mime === 'application/pdf') {
            AttachmentException::require(str_starts_with($content, '%PDF-') && ! preg_match('~/JavaScript|/JS\b|/Launch|/EmbeddedFile~i', $content), 422, 'file_type');
        }

        return $mime;
    }
}
