<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** The sole intentionally frameable public track surface; it has no account or purchase actions. */
final class PublicTrackEmbedResponse
{
    public static function matches(Request $request): bool
    {
        return $request->is('embed/tracks', 'embed/tracks/*');
    }

    public static function protect(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'autoplay=(), camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; style-src 'self'; font-src 'self'; media-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors http: https:");
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    public static function error(int $status, Response $original): Response
    {
        $headers = ['Content-Type' => 'text/plain; charset=UTF-8'];
        foreach (['Allow', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $name) {
            if ($original->headers->has($name)) { $headers[$name] = $original->headers->get($name); }
        }

        return self::protect(response('This preview is unavailable.', $status >= 500 ? 503 : $status, $headers));
    }
}
