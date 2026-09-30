<?php

namespace App\Jobs;

use App\Domain\SiteBuilder\SiteImageProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessSiteImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $imageId) {}

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(SiteImageProcessor $processor): void
    {
        $image = $processor->handle($this->imageId);
        // A temporary problem left the image quarantined; try again later. A synchronous queue cannot, so staff retry instead.
        if ($image->status === 'quarantined' && $image->failure_code !== null && $this->attempts() < $this->tries && $this->job !== null) {
            $this->release($this->backoff()[min($this->attempts(), count($this->backoff())) - 1]);
        }
    }

    /** The image stays quarantined, or its claim expires, so staff can retry it from the admin. */
    public function failed(?Throwable $exception): void
    {
        Log::warning('Site image processing stopped after its retries.', ['site_image_id' => $this->imageId, 'exception_class' => $exception === null ? null : $exception::class]);
    }
}
