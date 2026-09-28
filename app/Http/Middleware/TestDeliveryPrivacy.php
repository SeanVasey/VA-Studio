<?php

namespace App\Http\Middleware;

use App\Http\Responses\TestDeliveryResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs before sessions and CSRF; framework exceptions receive the same boundary in bootstrap/app.php. */
final class TestDeliveryPrivacy
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! TestDeliveryResponse::matches($request)) { return $next($request); }
        $allowed = $request->is('orders/*/delivery') ? ['GET', 'HEAD']
            : ($request->is('orders/*/delivery/authorizations', 'orders/*/delivery/download') ? ['POST'] : []);
        if ($allowed !== [] && ! in_array($request->getRealMethod(), $allowed, true)) {
            return TestDeliveryResponse::error(405, headers: ['Allow' => implode(', ', $allowed)]);
        }
        if ($request->headers->has('Range') || $request->headers->has('If-Range')) {
            return TestDeliveryResponse::error(416);
        }
        if ($request->query->count() !== 0 || $request->server->get('QUERY_STRING', '') !== '') {
            return TestDeliveryResponse::error(422);
        }
        if (! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
            return TestDeliveryResponse::error(415);
        }
        $length = $request->header('Content-Length');
        if ($length !== null && (! ctype_digit($length) || (float) $length > 4096)) {
            return TestDeliveryResponse::error(413);
        }
        // Content-Length is untrusted and may be absent for chunked requests. Inspect at most MAX + 1 bytes.
        $input = $request->getContent(true);
        $body = is_resource($input) ? stream_get_contents($input, 4097) : false;
        if ($body === false) { return TestDeliveryResponse::error(503); }
        if (strlen($body) > 4096) { return TestDeliveryResponse::error(413); }
        $request->attributes->set('_test_delivery_body', $body);
        if (in_array($request->getRealMethod(), ['GET', 'HEAD'], true) && $body !== '') {
            return TestDeliveryResponse::error(422);
        }
        if ($request->getRealMethod() !== $request->method()) { return TestDeliveryResponse::error(405); }
        return TestDeliveryResponse::protect($next($request));
    }
}
