<?php

namespace App\Jobs;

use App\Domain\Catalog\DiscoverySitemap\SitemapException;
use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One encrypted private job = one exact ordinal. No automatic unbounded chain or public request rebuild. */
final class BuildDiscoverySitemapWindow implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(private string $producerRequest, private int $ordinal) {}

    public function backoff(): array
    {
        return [1, 5, 15];
    }

    public function handle(SitemapStore $store): void
    {
        try {
            $store->step($this->producerRequest, $this->ordinal);
        } catch (SitemapException $error) {
            // Stale/policy/validation failures are permanent; transport/unknown acknowledgements retry the exact ordinal.
            $this->fail($error);
        }
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'private_sitemap_window_job', 'ordinal' => $this->ordinal];
    }
}
