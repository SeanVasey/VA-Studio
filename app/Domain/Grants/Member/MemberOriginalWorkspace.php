<?php

namespace App\Domain\Grants\Member;

use Throwable;
use UnexpectedValueException;

/** One ephemeral child workspace; never an original or another invocation's directory. */
final class MemberOriginalWorkspace
{
    private bool $closed = false;

    private function __construct(
        public readonly string $path,
        private readonly array $identity,
        private readonly MemberOriginalIoFrame $idle,
        private readonly MemberOriginalBudget $budget,
    ) {}

    public static function create(string $parent, MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): self
    {
        $idle->assertStillIdle();
        $budget->assertCurrent();
        if (str_contains($parent, PATH_SEPARATOR) || str_contains($parent, "\0") || ! str_starts_with($parent, '/')) {
            throw new MemberGrantException('storage_failed');
        }
        self::nativeFilesystem($idle, $budget);
        $path = $parent.'/'.bin2hex(random_bytes(16));
        if (! @mkdir($path, 0700)) {
            throw new MemberGrantException('storage_failed');
        }
        $idle->assertStillIdle();
        $budget->assertCurrent();
        self::nativeFilesystem($idle, $budget);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0177777) !== 0040700 || realpath($path) !== $path) {
            throw new MemberGrantException('storage_failed');
        }
        $idle->assertStillIdle();
        $budget->assertCurrent();

        return new self($path, $stat, $idle, $budget);
    }

    public function close(): void
    {
        try {
            if ($this->closed) {
                throw new UnexpectedValueException;
            }
            $this->sameDirectory($this->path, $this->identity);
            $remaining = 4096;
            $this->removeContents($this->path, $this->identity, 0, $remaining);
            $this->sameDirectory($this->path, $this->identity);
            if (! $this->io(fn () => @rmdir($this->path))) {
                throw new UnexpectedValueException;
            }
            $this->closed = true;
        } catch (Throwable) {
            throw new MemberGrantException('storage_failed');
        }
    }

    private function removeContents(string $directory, array $identity, int $depth, int &$remaining): void
    {
        if ($depth > 16) {
            throw new UnexpectedValueException;
        }
        $this->sameDirectory($directory, $identity);
        $entries = $this->io(fn () => @opendir($directory));
        if (! is_resource($entries)) {
            throw new UnexpectedValueException;
        }
        try {
            $metadata = $this->io(fn () => stream_get_meta_data($entries));
            if (($metadata['wrapper_type'] ?? null) !== 'plainfile' || ($metadata['stream_type'] ?? null) !== 'dir') {
                throw new UnexpectedValueException;
            }
            $this->sameDirectory($directory, $identity);
            while (($name = $this->io(fn () => readdir($entries))) !== false) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                if (--$remaining < 0) {
                    throw new UnexpectedValueException;
                }
                $this->sameDirectory($this->path, $this->identity);
                $this->sameDirectory($directory, $identity);
                $path = $directory.'/'.$name;
                $stat = $this->io(fn () => $this->stat($path));
                if (! is_array($stat)) {
                    throw new UnexpectedValueException;
                }
                if (($stat['mode'] & 0170000) === 0040000) {
                    $this->removeContents($path, $stat, $depth + 1, $remaining);
                    $this->sameDirectory($path, $stat);
                    if (! $this->io(fn () => @rmdir($path))) {
                        throw new UnexpectedValueException;
                    }
                } else {
                    // Remove a symlink entry itself, never traverse its target. No FIFO/device I/O.
                    if (! in_array($stat['mode'] & 0170000, [0100000, 0120000], true)) {
                        throw new UnexpectedValueException;
                    }
                    $current = $this->io(fn () => $this->stat($path));
                    if (! is_array($current) || $current['ino'] !== $stat['ino'] || $current['dev'] !== $stat['dev']
                        || $current['mode'] !== $stat['mode']) {
                        throw new UnexpectedValueException;
                    }
                    if (! $this->io(fn () => @unlink($path))) {
                        throw new UnexpectedValueException;
                    }
                }
            }
        } finally {
            closedir($entries);
        }
    }

    private function sameDirectory(string $path, array $identity): void
    {
        self::nativeFilesystem($this->idle, $this->budget);
        $current = $this->io(fn () => $this->stat($path));
        if (! is_array($current) || ($current['mode'] & 0177777) !== 0040700
            || $current['dev'] !== $identity['dev'] || $current['ino'] !== $identity['ino']
            || $this->io(fn () => realpath($path)) !== $path) {
            throw new UnexpectedValueException;
        }
    }

    private function stat(string $path): array|false
    {
        clearstatcache(true, $path);

        return @lstat($path);
    }

    /** Admit native files before a substituted stat wrapper can restore itself in a callback. */
    private static function nativeFilesystem(MemberOriginalIoFrame $idle, MemberOriginalBudget $budget): void
    {
        $probe = null;
        try {
            $idle->assertStillIdle();
            $budget->assertCurrent();
            $probe = @fopen(__FILE__, 'rb');
            $idle->assertStillIdle();
            $budget->assertCurrent();
            if (! is_resource($probe) || get_resource_type($probe) !== 'stream') {
                throw new UnexpectedValueException;
            }
            $metadata = stream_get_meta_data($probe);
            $idle->assertStillIdle();
            $budget->assertCurrent();
            if (($metadata['wrapper_type'] ?? null) !== 'plainfile' || ($metadata['stream_type'] ?? null) !== 'STDIO'
                || ($metadata['uri'] ?? null) !== __FILE__) {
                throw new UnexpectedValueException;
            }
        } finally {
            if (is_resource($probe)) {
                fclose($probe);
            }
        }
    }

    private function io(callable $operation): mixed
    {
        $this->idle->assertStillIdle();
        $this->budget->assertCurrent();
        $result = $operation();
        $this->idle->assertStillIdle();
        $this->budget->assertCurrent();

        return $result;
    }

    public function __clone(): void
    {
        throw new MemberGrantException;
    }

    public function __serialize(): array
    {
        throw new MemberGrantException;
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException;
    }

    public function __debugInfo(): array
    {
        return ['closed' => $this->closed];
    }
}
