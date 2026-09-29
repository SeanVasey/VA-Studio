<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Apply privacy before panel authentication/bindings and to exception responses too. */
final class SitePreviewPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('admin/site-releases/*/preview', 'admin/site-releases/*/preview/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return self::matches($request) ? self::protect($response) : $response;
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie', 'X-Inertia'])));

        return $response;
    }

    public static function error(int $status, Response $original): Response
    {
        $headers = [];
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
            if ($original->headers->has($name)) { $headers[$name] = $original->headers->get($name); }
        }

        return self::protect(response('Site content preview is unavailable.', $status >= 500 ? 503 : $status, $headers));
    }
}
