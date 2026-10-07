<?php

namespace App\Domain\Catalog\DiscoverySitemap;

use App\Domain\SiteBuilder\PublicPagesSitemap;
use App\Support\CanonicalJson;

/** Versioned application bounds; no assertion about actual catalog size or source-URL acceptance. */
final class SitemapConfiguration
{
    public const SLOTS = 128;

    public const IDS = 48;

    public const GENERATION_SECONDS = 3600;

    public const XML_BYTES = 131072;

    public const INDEX_BYTES = 524288;

    /** Pure config/clock identity: no container resolution, ORM, I/O or callbacks. */
    public static function hash(): string
    {
        return CanonicalJson::hash(['schema' => 'discovery-sitemap-v1', 'slots' => self::SLOTS, 'ids' => self::IDS,
            'generation_seconds' => self::GENERATION_SECONDS, 'environment' => app()->environment(),
            'origin' => config('app.url'), 'media' => config('media'), 'commerce' => config('commerce'),
            'filesystems' => config('filesystems'), 'key' => hash('sha256', (string) config('app.key')),
            'enabled' => config('discovery-sitemap.enabled')]);
    }

    public static function assertEnabled(): void
    {
        SitemapException::require(config('discovery-sitemap.enabled') === true, 'disabled', 404);
    }

    /** Resolve canonical helper before taking terminal evidence, never after its raw fence. */
    public static function origin(): string
    {
        return app(PublicPagesSitemap::class)->canonicalOrigin();
    }
}
