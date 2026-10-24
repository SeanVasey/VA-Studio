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
        try {
            $map = LegacyRedirectMap::fromFile((string) config('legacy-redirects.map_path'));
        } catch (LegacyRedirectMapInvalid $invalid) {
            Log::warning('Legacy redirect map refused; no legacy redirects are served.', ['problems' => array_slice($invalid->problems, 0, 10)]);

            return null;
        }
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
}
