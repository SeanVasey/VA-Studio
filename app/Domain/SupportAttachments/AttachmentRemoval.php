<?php

namespace App\Domain\SupportAttachments;

/** Prepared outside locks; after positive tombstone commit only callback-free native erasure remains. */
final class AttachmentRemoval
{
    public function __construct(private string $path, private $lease, private $original, private array $identity, private string $configuration, private array $file) {}

    public function erase(): void
    {
        AttachmentException::require(DBOutside::check() && $this->configuration === self::configuration());
        clearstatcache(true, $this->path);
        $current = @lstat($this->path);
        if ($this->original === null) {
            AttachmentException::require($current === false && ! is_link($this->path));

            return;
        }
        AttachmentException::require(is_resource($this->original) && is_array($current) && $current['nlink'] === 1 && fstat($this->original)['nlink'] === 1
            && self::identity($current) === $this->identity && self::identity(fstat($this->original)) === $this->identity && realpath($this->path) === $this->path);
        AttachmentException::require($current['size'] === $this->file['sizeBytes'] && $current['size'] >= 1 && $current['size'] <= 5242880 && fseek($this->original, 0) === 0);
        $hash = hash_init('sha256');
        $bytes = 0;
        $deadline = hrtime(true) + 30_000_000_000;
        while (! feof($this->original)) {
            AttachmentException::require(hrtime(true) <= $deadline);
            $chunk = fread($this->original, min(1048576, $this->file['sizeBytes'] - $bytes + 1));
            AttachmentException::require(is_string($chunk) && ($chunk !== '' || feof($this->original)));
            $bytes += strlen($chunk);
            AttachmentException::require($bytes <= $this->file['sizeBytes']);
            hash_update($hash, $chunk);
        }
        clearstatcache(true, $this->path);
        AttachmentException::require($bytes === $this->file['sizeBytes'] && hash_equals($this->file['sha256'], hash_final($hash))
            && self::identity(@lstat($this->path) ?: []) === $this->identity && self::identity(fstat($this->original)) === $this->identity
            && $this->configuration === self::configuration());
        AttachmentException::require(unlink($this->path));
    }

    public static function configuration(): string
    {
        return AttachmentRegistry::hash((array) config('filesystems'));
    }

    public static function identity(array $stat): array
    {
        return array_intersect_key($stat, array_flip(['dev', 'ino', 'size', 'mode', 'uid', 'gid', 'mtime', 'ctime']));
    }

    public function close(): void
    {
        if (is_resource($this->original)) {
            fclose($this->original);
            $this->original = null;
        }
        if (is_resource($this->lease)) {
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
        throw new \LogicException('Prepared erasure cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['prepared' => is_resource($this->lease)];
    }
}
