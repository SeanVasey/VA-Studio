<?php

namespace Tests\Support;

/** A file that opens, takes chmod and is removed, but cannot be grown: ftruncate fails, as it does on a disk with no room left. */
class FullDiskStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_truncate(int $size): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    public function unlink(string $path): bool
    {
        return true;
    }
}
