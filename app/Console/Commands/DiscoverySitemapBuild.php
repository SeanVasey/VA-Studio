<?php

namespace App\Console\Commands;

use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Console\Command;
use Throwable;

/** Explicit bounded private operations. A reviewed host runner may enqueue the encrypted one-window job instead. */
final class DiscoverySitemapBuild extends Command
{
    protected $signature = 'discovery-sitemap:build {--new-request=} {--request-file=} {--start} {--ordinal=} {--publish-revision=} {--status}';

    protected $description = 'Perform one private bounded discovery sitemap operation';

    public function handle(SitemapStore $store): int
    {
        try {
            if (is_string($this->option('new-request'))) {
                if ($this->option('request-file') !== null || $this->option('start') || $this->option('ordinal') !== null || $this->option('publish-revision') !== null || $this->option('status')) {
                    throw new \LogicException;
                }
                $request = $store->newRequest();
                $path = $this->option('new-request');
                $file = fopen($path, 'x');
                if ($file === false) {
                    throw new \LogicException;
                }
                try {
                    if (! chmod($path, 0600) || fwrite($file, $request) !== strlen($request) || ! fflush($file)) {
                        throw new \LogicException;
                    }
                } finally {
                    fclose($file);
                }
                $this->info('Private request created; generation has not started.');

                return self::SUCCESS;
            }
            $path = $this->option('request-file');
            if (! is_string($path) || is_link($path) || ! is_file($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 8192) {
                throw new \LogicException;
            }
            $request = file_get_contents($path);
            $operations = (int) $this->option('start') + (int) $this->option('status') + (int) ($this->option('ordinal') !== null) + (int) ($this->option('publish-revision') !== null);
            if ($operations !== 1 || ! is_string($request)) {
                throw new \LogicException;
            }
            if ($this->option('start')) {
                $result = $store->start($request);
            } elseif ($this->option('status')) {
                $result = $store->status($request);
            } elseif ($this->option('ordinal') !== null) {
                $result = $store->step($request, $this->integer($this->option('ordinal')));
            } else {
                $result = ['published_generation' => $store->publish($request, $this->integer($this->option('publish-revision')))];
            }
            // The private capability and source identities are never echoed or logged.
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Sitemap operation unavailable. Preserve the private request and verify status before retrying.');

            return self::FAILURE;
        }
    }

    private function integer(mixed $value): int
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $value) !== 1 || (int) $value > 2147483647) {
            throw new \LogicException;
        }

        return (int) $value;
    }
}
