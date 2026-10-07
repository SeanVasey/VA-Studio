<?php

namespace App\Http\Middleware;

use App\Http\Responses\ProductionCheckoutResponse as PrivateResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Integrator must prepend globally so unmatched methods and CSRF/session/throttle failures are private too. */
final class ProductionCheckoutPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! PrivateResponse::matches($request)) {
            return $next($request);
        }
        if (config('production_checkout.http_enabled') !== true) {
            return PrivateResponse::error(503);
        }
        if (! in_array($request->getRealMethod(), ['GET', 'POST'], true)
            || $request->getRealMethod() !== $request->method() || $request->headers->has('X-HTTP-Method-Override')) {
            return PrivateResponse::error(405);
        }
        if ($request->query->count() || $request->server->get('QUERY_STRING', '') !== ''
            || $request->headers->has('Range') || $request->headers->has('If-Range')) {
            return PrivateResponse::error(422);
        }
        if (($request->hasHeader('Origin') && $request->header('Origin') !== $request->getSchemeAndHttpHost())
            || strtolower($request->header('Sec-Fetch-Site', '')) === 'cross-site') {
            return PrivateResponse::error(403);
        }
        if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
            return PrivateResponse::error(415);
        }
        $maximum = $request->getRealMethod() === 'POST' ? 16384 : 0;
        if ($maximum > 0 && preg_match('~\Aapplication/json(?:\s*;\s*charset=(?:utf-8|"utf-8"))?\z~iD', $request->header('Content-Type', '')) !== 1) {
            return PrivateResponse::error(415);
        }
        $length = $request->header('Content-Length');
        if ($length !== null && (! ctype_digit($length) || (float) $length > $maximum)) {
            return PrivateResponse::error($maximum > 0 ? 413 : 422);
        }
        $input = $request->getContent(true);
        $body = is_resource($input) ? stream_get_contents($input, $maximum + 1) : false;
        if ($body === false) {
            return PrivateResponse::error(503);
        }
        if (strlen($body) > $maximum) {
            return PrivateResponse::error($maximum > 0 ? 413 : 422);
        }
        $request->attributes->set('_production_checkout_body', $body);
        try {
            return PrivateResponse::protect($next($request));
        } catch (Throwable $error) {
            // Never report exception text, SQL bindings, provider bodies or identity proofs.
            return PrivateResponse::error($error instanceof HttpExceptionInterface ? $error->getStatusCode() : 503);
        }
    }
}
