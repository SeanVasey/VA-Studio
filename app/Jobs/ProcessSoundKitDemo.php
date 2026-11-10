<?php

namespace App\Jobs;

use App\Domain\SoundKits\Products\SoundKitDemoProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSoundKitDemo implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $demoId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SoundKitDemoProcessor $processor): void
    {
        $demo = $processor->handle($this->demoId);
        // A temporary problem left the demo quarantined; try again later. A synchronous queue cannot, so staff retry instead.
        if ($demo->status === 'quarantined' && $demo->failure_code !== null && $this->attempts() < $this->tries && $this->job !== null) {
            $this->release($this->backoff()[min($this->attempts(), count($this->backoff())) - 1]);
        }
    }

    /** The demo stays quarantined, or its claim expires, so staff can retry it from the admin. */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Sound-kit demo processing stopped after its retries.', ['sound_kit_demo_id' => $this->demoId, 'exception_class' => $exception === null ? null : $exception::class]);
    }
}
