<?php

namespace App\Domain\Migration\LegacyRedirects;

use App\Domain\Catalog\PublicCatalog;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/** Shared by the validation command and the request path, so both judge a target by the same rules. */
final class LegacyRedirectTargets
{
    /** The route that would answer this request, or null when none matches. A match on another method still counts. */
    public function servingRoute(Request $request): ?string
    {
        try {
            $route = Route::getRoutes()->match($request);

            return $route->getName() ?? '/'.ltrim($route->uri(), '/');
        } catch (MethodNotAllowedHttpException) {
            return '(another method)';
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    public function servingRouteForPath(string $path): ?string
    {
        return $this->servingRoute(Request::create($path, 'GET'));
    }

    /**
     * Static resolution: no database read. Internal targets become a path on an existing GET route; external targets
     * must be https on the map's own reviewed allowlist. A target that is another entry's redirect or gone source is a
     * chain (or a loop when it is the entry's own source) and is refused, so every redirect stays one hop.
     *
     * @return array{path: string|null, url: string|null, problem: string|null}
     */
    public function describe(array $entry, LegacyRedirectMap $map): array
    {
        $target = $entry['target'];
        $path = null;
        $url = null;
        try {
            switch ($target['type']) {
                case 'route':
                    $route = Route::getRoutes()->getByName($target['name']);
                    if ($route === null) {
                        return $this->problem('route '.$target['name'].' does not exist');
                    }
                    if (! in_array('GET', $route->methods(), true)) {
                        return $this->problem('route '.$target['name'].' does not answer GET');
                    }
                    $path = route($target['name'], [], false);
                    break;
                case 'editorial':
                    if (! Route::has('editorial.show')) {
                        return $this->problem('the editorial route does not exist');
                    }
                    $path = route('editorial.show', array_filter(['section' => $target['section'], 'slug' => $target['slug']], fn ($value) => $value !== null), false);
                    break;
                case 'track':
                    if (! Route::has('tracks.show')) {
                        return $this->problem('the track route does not exist');
                    }
                    $path = route('tracks.show', ['slug' => $target['slug']], false);
                    break;
                case 'external':
                    $url = $target['url'];
                    $parts = parse_url($url);
                    if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! isset($parts['host'])
                        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
                        || filter_var($url, FILTER_VALIDATE_URL) === false) {
                        return $this->problem('external target must be a plain https URL without credentials, port or fragment');
                    }
                    if (! in_array($parts['host'], $map->externalHosts, true)) {
                        return $this->problem('external host '.$parts['host'].' is not on the map\'s external_hosts allowlist (open redirect refused)');
                    }
                    foreach ($map->entries as $other) {
                        if ($other['source_host'] === $parts['host']) {
                            return $this->problem('external target points back at the legacy host '.$parts['host']);
                        }
                    }

                    return ['path' => null, 'url' => $url, 'problem' => null];
            }
        } catch (UrlGenerationException) {
            return $this->problem('target route needs parameters the map does not supply');
        }
        $path = '/'.trim((string) parse_url((string) $path, PHP_URL_PATH), '/');
        if ($path === $entry['source_path']) {
            return $this->problem('target is the entry\'s own source (loop)');
        }
        $next = $map->entryForPath($path);
        if ($next !== null && in_array($next['disposition'], ['redirect', 'gone'], true)) {
            return $this->problem('target '.$path.' is the source of '.$next['route_id'].' (redirect chain)');
        }
        if ($this->servingRouteForPath($path) === null) {
            return $this->problem('no route serves target '.$path);
        }

        return ['path' => $path, 'url' => $url, 'problem' => null];
    }

    /**
     * Live check against current public content. A draft, unpublished, withdrawn or otherwise ineligible track, or an
     * editorial page absent from the active site release, is not public, and a redirect must not reveal or point at it.
     */
    public function currentlyPublic(array $target): bool
    {
        try {
            return match ($target['type']) {
                'editorial' => app(EditorialContent::class)->page(app(SiteContent::class)->current(), $target['section'], $target['slug'], false) !== null,
                'track' => app(PublicCatalog::class)->track($target['slug'])['tracks'] !== [],
                default => true,
            };
        } catch (HttpExceptionInterface) {
            return false;
        } catch (Throwable $error) {
            report($error);

            return false;
        }
    }

    /** @return array{path: null, url: null, problem: string} */
    private function problem(string $problem): array
    {
        return ['path' => null, 'url' => null, 'problem' => $problem];
    }
}
