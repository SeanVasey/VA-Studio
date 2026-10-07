<?php

namespace App\Http\Middleware;

use App\Http\Requests\CustomerListeningRequest;
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
            $limit = $request->is('account/listening-library', 'account/listening-library/export')
                ? CustomerListeningRequest::BODY_BYTES : 4096;
            $body = is_resource($stream) ? stream_get_contents($stream, $limit + 1) : false;
            if ($body === false || strlen($body) > $limit) {
                return self::error(413);
            }
            $request->attributes->set('_customer_body', $body);
        } elseif ($request->is('account/create', 'account/recover', 'account/access', 'account/identity/*')) {
            $stream = $request->getContent(true);
            if (! $request->isMethod('GET') || ! is_resource($stream) || stream_get_contents($stream, 1) !== '') {
                return self::error(422);
            }
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
        if (request()->is('account/listening-library', 'account/listening-library/*')) {
            return self::protect(response()->json(['code' => 'CUSTOMER_LISTENING_UNAVAILABLE',
                'message' => 'Saved tracks and playlists could not be confirmed. Reload your library before making another change.'], $status >= 500 ? 503 : $status));
        }
        if (request()->is('account/create', 'account/recover', 'account/access', 'account/identity/*')) {
            return self::protect(response()->json(['code' => 'CUSTOMER_IDENTITY_UNAVAILABLE',
                'message' => 'This account request could not be completed. Try the original request again or request a new message.'], $status >= 500 ? 503 : $status));
        }

        return self::protect(response()->json(['code' => 'CUSTOMER_SIGN_IN_UNAVAILABLE',
            'message' => 'Sign-in could not be completed. Check your details and try again.'], $status >= 500 ? 503 : $status));
    }
}
