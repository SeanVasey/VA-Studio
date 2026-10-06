<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CustomerPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('account', 'account/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::matches($request)) {
            return $next($request);
        }
        if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== ''
            || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')
            || $request->headers->has('Range') || $request->headers->has('If-Range')
            || ! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
            return self::error(422);
        }
        if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
            || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            return self::error(403);
        }
        if ($request->isMethod('POST')) {
            if (! $request->isJson()) {
                return self::error(415);
            }
            $stream = $request->getContent(true);
            $body = is_resource($stream) ? stream_get_contents($stream, 4097) : false;
            if ($body === false || strlen($body) > 4096) {
                return self::error(413);
            }
            $request->attributes->set('_customer_body', $body);
        }

        return self::protect($next($request));
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

    public static function error(int $status): Response
    {
        return self::protect(response()->json(['code' => 'CUSTOMER_SIGN_IN_UNAVAILABLE',
            'message' => 'Sign-in could not be completed. Check your details and try again.'], $status >= 500 ? 503 : $status));
    }
}
