<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Protect track administration and its exact authenticated derivative responses. */
final class PrivateTrackReviewPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('admin/tracks', 'admin/tracks/*', 'admin/media/*/preview', 'admin/media/*/preview/*')
            || $request->attributes->get('_track_private_review') === true;
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
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie'])));

        return $response;
    }

    public static function error(int $status, Response $original): Response
    {
        $headers = [];
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
            if ($original->headers->has($name)) {
                $headers[$name] = $original->headers->get($name);
            }
        }

        return self::protect(response('Private track review is unavailable.', $status >= 500 ? 503 : $status, $headers));
    }
}
