<?php

namespace App\Jobs;

use App\Domain\Media\MediaProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $runId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(MediaProcessor $processor): void
    {
        $processor->handle($this->runId);
    }
}
