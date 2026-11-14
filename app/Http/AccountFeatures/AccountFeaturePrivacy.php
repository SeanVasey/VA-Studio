<?php

namespace App\Http\AccountFeatures;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AccountFeaturePrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('customer/features', 'customer/features/*');
    }

    public static function headers(): array
    {
        return ['Content-Type' => 'application/json', 'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer',
            'X-Content-Type-Options' => 'nosniff', 'Vary' => 'Cookie'];
    }

    public static function error(int $status): Response
    {
        return new Response('{"code":"ACCOUNT_FEATURE_UNAVAILABLE"}', $status >= 500 ? 503 : $status, self::headers());
    }

    public static function protect(Response $response): Response
    {
        foreach (self::headers() as $key => $value) {
            if ($key !== 'Content-Type' && $key !== 'Vary') {
                $response->headers->set($key, $value);
            }
        }
        $response->setVary(array_values(array_unique([...$response->getVary(), 'Cookie'])));

        return $response;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if (! AccountFeatureMount::admitted()) {
            return self::error(503);
        }
        if ($request->query->count() !== 0 || $request->server->get('QUERY_STRING', '') !== ''
            || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')
            || $request->headers->has('Range') || $request->headers->has('If-Range')
            || ! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)
            || (! $request->is('customer/features') && $request->headers->has('X-Inertia'))) {
            return self::error(422);
        }
        if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
            || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            return self::error(403);
        }
        if (! in_array($request->getRealMethod(), ['GET', 'POST'], true)) {
            return self::error(405);
        }
        $cap = $request->isMethod('GET') ? 0 : ($request->is('customer/features/listening/change') ? 16384 : 4096);
        if ($request->isMethod('POST') && ! $request->isJson()) {
            return self::error(415);
        }
        $stream = $request->getContent(true);
        $body = is_resource($stream) ? stream_get_contents($stream, $cap + 1) : false;
        if ($body === false || strlen($body) > $cap) {
            return self::error($cap === 0 ? 422 : 413);
        }
        $request->attributes->set('_production_feature_body', $body);

        return self::protect($next($request));
    }
}
