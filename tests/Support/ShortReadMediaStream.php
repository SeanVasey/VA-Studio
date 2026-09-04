<?php

namespace Tests\Support;

/** Simulates a source failing before the byte count reported by stat is read. */
class ShortReadMediaStream
{
    public $context;

    private int $position = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr('partial', $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= 7;
    }

    public function stream_stat(): array
    {
        return ['size' => 64, 'mode' => 0100600];
    }

    public function url_stat(string $path, int $flags): array
    {
        return $this->stream_stat();
    }
}
