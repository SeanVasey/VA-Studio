<?php

namespace App\Domain\SupportAttachments;

/** Private bounded snapshot. Scanner gets a protected local path; browser never does. */
final class AttachmentSnapshot
{
    private $reader = null;

    private array $identity;

    public function __construct(private string $path, private $lease, private array $entry)
    {
        $stat = @lstat($path);
        AttachmentException::require(is_array($stat));
        $this->identity = array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'uid', 'gid', 'mtime', 'ctime']));
        $this->verify();
    }

    public function scannerPath(): string
    {
        AttachmentException::require($this->reader === null);
        $this->verify();

        return $this->path;
    }

    public function verify(): void
    {
        clearstatcache(true, $this->path);
        $stat = @lstat($this->path);
        AttachmentException::require(is_array($stat) && ($stat['mode'] & 0170000) === 0100000 && ($stat['mode'] & 07777) === 0400
            && $stat['nlink'] === 1 && $stat['size'] === $this->entry['sizeBytes'] && realpath($this->path) === $this->path
            && array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'uid', 'gid', 'mtime', 'ctime'])) === $this->identity
            && hash_equals($this->entry['sha256'], (string) hash_file('sha256', $this->path)));
    }

    public function seal(): void
    {
        $this->verify();
        $this->reader = fopen($this->path, 'rb');
        AttachmentException::require(is_resource($this->reader));
        $opened = fstat($this->reader);
        $this->verify();
        $named = lstat($this->path);
        AttachmentException::require($opened['ino'] === $named['ino'] && $opened['dev'] === $named['dev'] && unlink($this->path));
        AttachmentException::require(fstat($this->reader)['nlink'] === 0);
    }

    public function writeTo($output): void
    {
        AttachmentException::require(DBOutside::check() && is_resource($this->reader));
        try {
            $bytes = 0;
            $hash = hash_init('sha256');
            $deadline = hrtime(true) + 30_000_000_000;
            while (! feof($this->reader)) {
                AttachmentException::require(hrtime(true) <= $deadline);
                $chunk = fread($this->reader, min(1048576, $this->entry['sizeBytes'] - $bytes + 1));
                AttachmentException::require(is_string($chunk) && ($chunk !== '' || feof($this->reader)));
                $bytes += strlen($chunk);
                AttachmentException::require($bytes <= $this->entry['sizeBytes']);
                hash_update($hash, $chunk);
                while ($chunk !== '') {
                    $written = fwrite($output, $chunk);
                    AttachmentException::require(is_int($written) && $written > 0);
                    $chunk = substr($chunk, $written);
                }
            }
            AttachmentException::require($bytes === $this->entry['sizeBytes'] && hash_equals($this->entry['sha256'], hash_final($hash)));
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if (is_resource($this->reader)) {
            fclose($this->reader);
            $this->reader = null;
        }
        if (is_resource($this->lease)) {
            clearstatcache(true, $this->path);
            $stat = @lstat($this->path);
            if (is_array($stat) && $stat['dev'] === $this->identity['dev'] && $stat['ino'] === $this->identity['ino'] && ($stat['mode'] & 0170000) === 0100000) {
                @unlink($this->path);
            }
            flock($this->lease, LOCK_UN);
            fclose($this->lease);
            $this->lease = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function __serialize(): array
    {
        throw new \LogicException('Private snapshot cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['prepared' => is_resource($this->lease)];
    }
}
