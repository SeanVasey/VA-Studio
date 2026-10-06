<?php

namespace App\Jobs;

use App\Domain\SoundKits\SoundKitProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSoundKit implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $revisionId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SoundKitProcessor $processor): void
    {
        $revision = $processor->handle($this->revisionId);
        if ($revision->status === 'quarantined' && $revision->failure_code !== null && $this->attempts() < $this->tries && $this->job !== null) {
            $this->release($this->backoff()[min($this->attempts(), 2) - 1]);
        }
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Kit processing stopped; its private revision remains available for inspection.', [
            'revision_id' => $this->revisionId, 'exception_class' => $exception === null ? null : $exception::class]);
    }
}
