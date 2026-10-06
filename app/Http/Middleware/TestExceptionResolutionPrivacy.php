<?php

namespace App\Http\Middleware;

use App\Http\Responses\TestExceptionResolutionResponse as Boundary;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Private transport runs before session work; framework failures share the same response boundary. */
final class TestExceptionResolutionPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Boundary::matches($request)) {
            return $next($request);
        }
        if ($request->getRealMethod() !== 'GET') {
            return Boundary::error(405, ['Allow' => 'GET']);
        }
        if ($request->headers->has('Range') || $request->headers->has('If-Range')) {
            return Boundary::error(416);
        }
        if ($request->query->count() !== 0 || $request->server->get('QUERY_STRING', '') !== ''
            || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')) {
            return Boundary::error(422);
        }
        if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
            return Boundary::error(415);
        }
        if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
            || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            return Boundary::error(403);
        }
        $input = $request->getContent(true);
        if (! is_resource($input) || stream_get_contents($input, 1) !== '') {
            return Boundary::error(422);
        }
        $response = $next($request);

        return $response->getStatusCode() >= 400
            ? Boundary::error($response->getStatusCode(), array_filter([
                'Allow' => $response->headers->get('Allow'), 'Retry-After' => $response->headers->get('Retry-After'),
            ], fn ($value) => $value !== null)) : Boundary::protect($response);
    }
}
