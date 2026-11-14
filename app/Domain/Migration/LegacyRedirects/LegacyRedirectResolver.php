<?php

namespace App\Domain\Migration\LegacyRedirects;

use App\Domain\SiteBuilder\PublicPagesSitemap;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Request-time redirect decision. Acts only for GET or HEAD on an exact map source that no route matches. The query
 * string is dropped; the Location uses the canonical APP_URL origin, never the request Host. Anything uncertain (an
 * invalid map, a refused target, content that is not public now) yields null, so the request falls through to the
 * ordinary 404 and nothing private is confirmed.
 */
final class LegacyRedirectResolver
{
    /** Bounded browser and shared-cache lifetime, so a corrected map takes effect within the hour. */
    public const CACHE_CONTROL = 'public, max-age=3600';

    /** The route-level fallback sets session cookies, so a shared cache must not store it. */
    public const FALLBACK_CACHE_CONTROL = 'private, max-age=3600';

    public function __construct(private readonly LegacyRedirectTargets $targets) {}

    public function respond(Request $request): ?RedirectResponse
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return null;
        }
        $path = '/'.trim($request->getPathInfo(), '/');
        // Cheap shape test first: only an exact lowercase path can ever be a map source.
        if (preg_match(LegacyRedirectMap::PATH_PATTERN, $path) !== 1) {
            return null;
        }
        $mapPath = (string) config('legacy-redirects.map_path');
        try {
            $map = LegacyRedirectMap::fromFile($mapPath);
        } catch (LegacyRedirectMapInvalid) {
            app(LegacyRedirectMapWarnings::class)->invalid($mapPath);

            return null;
        }
        app(LegacyRedirectMapWarnings::class)->repaired($mapPath);
        $entry = $map->entryForPath($path);
        if ($entry === null || ! in_array($entry['disposition'], ['redirect', 'gone'], true)) {
            return null;
        }
        if ($this->targets->servingRoute($request) !== null) {
            return null;
        }
        if ($entry['disposition'] === 'gone') {
            abort(410, '', ['Cache-Control' => self::CACHE_CONTROL]);
        }
        $target = $this->targets->describe($entry, $map);
        if ($target['problem'] !== null) {
            Log::warning('Legacy redirect target refused.', ['route_id' => $entry['route_id'], 'problem' => $target['problem']]);

            return null;
        }
        if (! $this->targets->currentlyPublic($entry['target'])) {
            return null;
        }
        $location = $target['url'] ?? app(PublicPagesSitemap::class)->canonicalOrigin().$target['path'];

        return new RedirectResponse($location, 301, ['Cache-Control' => self::CACHE_CONTROL]);
    }

    /**
     * Route-level fallback for the legacy /services path while the service catalog is switched off (URL-006). A real
     * route always wins over a map redirect, so the services controller asks for this instead. It follows the layer's own
     * switch (the literal `legacy-redirects.enabled`), uses the same canonical origin and query-free single hop as a map
     * redirect, and answers null (the ordinary 404) unless the contact page is public right now. It runs inside the web
     * group, so the response carries session cookies: it is cacheable by the browser only (`private`), never by a shared cache.
     */
    public function contactFallback(): ?RedirectResponse
    {
        return $this->routeFallback(['type' => 'editorial', 'section' => 'contact', 'slug' => null],
            fn (): string => route('editorial.show', ['section' => 'contact'], false));
    }

    /**
     * Route-level fallback for the legacy /sound-kits path while the sound-kit storefront is switched off (URL-005, Sean's
     * decision 2026-11-14): a 301 to the catalog, where the legacy path pointed before the storefront route existed. Same
     * switch, origin, single hop and private caching as the contact fallback.
     */
    public function catalogFallback(): ?RedirectResponse
    {
        return $this->routeFallback(['type' => 'route', 'name' => 'home'], fn (): string => route('home', [], false));
    }

    /** @param  callable(): string  $path */
    private function routeFallback(array $target, callable $path): ?RedirectResponse
    {
        if (config('legacy-redirects.enabled') !== true) {
            return null;
        }
        if (! $this->targets->currentlyPublic($target)) {
            return null;
        }

        return new RedirectResponse(
            app(PublicPagesSitemap::class)->canonicalOrigin().$path(),
            301,
            ['Cache-Control' => self::FALLBACK_CACHE_CONTROL],
        );
    }
}
