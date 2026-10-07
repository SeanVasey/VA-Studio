<?php

namespace App\Domain\Catalog\DiscoverySitemap;

/** Minimized internal distinction; public responses never echo reasons or private progress. */
final class SitemapException extends \RuntimeException
{
    public function __construct(public readonly string $reason = 'unavailable', public readonly int $status = 503)
    {
        parent::__construct('Discovery sitemap unavailable.');
    }

    public static function require(bool $condition, string $reason = 'unavailable', int $status = 503): void
    {
        if (! $condition) {
            throw new self($reason, $status);
        }
    }
}
