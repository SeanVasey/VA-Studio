<?php

namespace App\Domain\Contracts;

use Throwable;

/** One private child cache. Cleanup cannot target original-contract paths or a replacement directory. */
final class ContractRenderWorkspace
{
    private function __construct(public readonly string $path, private readonly array $identity) {}

    public static function create(string $parent): self
    {
        ContractIo::outsideTransactions();
        if (str_contains($parent, PATH_SEPARATOR)) { throw new ContractIssuanceException('storage_failed'); }
        $path = $parent.'/'.bin2hex(random_bytes(16));
        if (! @mkdir($path, 0700)) { throw new ContractIssuanceException('storage_failed'); }
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (! is_array($stat) || ($stat['mode'] & 0177777) !== 0040700 || realpath($path) !== $path) {
            throw new ContractIssuanceException('storage_failed');
        }

        return new self($path, $stat);
    }

    public function close(): void
    {
        ContractIo::outsideTransactions();
        try {
            clearstatcache(true, $this->path);
            $current = @lstat($this->path);
            if (! is_array($current) || ($current['mode'] & 0177777) !== 0040700
                || $current['dev'] !== $this->identity['dev'] || $current['ino'] !== $this->identity['ino']
                || realpath($this->path) !== $this->path) {
                throw new \UnexpectedValueException;
            }
            $remaining = 4096;
            $this->removeContents($this->path, 0, $remaining);
            if (! @rmdir($this->path)) { throw new \UnexpectedValueException; }
        } catch (Throwable) {
            throw new ContractIssuanceException('storage_failed');
        }
    }

    private function removeContents(string $directory, int $depth, int &$remaining): void
    {
        if ($depth > 16 || realpath($directory) !== $directory) { throw new \UnexpectedValueException; }
        $entries = @scandir($directory);
        if (! is_array($entries)) { throw new \UnexpectedValueException; }
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if (--$remaining < 0) { throw new \UnexpectedValueException; }
            $path = $directory.'/'.$name;
            clearstatcache(true, $path);
            $stat = @lstat($path);
            if (! is_array($stat)) { throw new \UnexpectedValueException; }
            if (($stat['mode'] & 0170000) === 0040000) {
                $this->removeContents($path, $depth + 1, $remaining);
                if (! @rmdir($path)) { throw new \UnexpectedValueException; }
            } elseif (! @unlink($path)) {
                // A symbolic link is removed itself; its target is never traversed.
                throw new \UnexpectedValueException;
            }
        }
    }
}
