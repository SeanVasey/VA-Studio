<?php

namespace App\Http\Middleware;

use App\Domain\Commerce\Payments\VerifyStripeWebhook;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs before global JSON input transformations so the limit precedes JSON decoding. */
final class StripeWebhookBodyLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('webhooks/stripe') || ! $request->isMethod('POST')) {
            return $next($request);
        }
        if (strtolower(trim(explode(';', $request->header('Content-Type', ''))[0])) !== 'application/json'
            || ! in_array(strtolower($request->header('Content-Encoding', 'identity')), ['', 'identity'], true)) {
            abort(415);
        }
        if ((int) $request->header('Content-Length', '0') > VerifyStripeWebhook::MAX_BODY_BYTES) {
            abort(413);
        }
        $stream = $request->getContent(true);
        $body = stream_get_contents($stream, VerifyStripeWebhook::MAX_BODY_BYTES + 1);
        if ($body === false) {
            abort(503);
        }
        if (strlen($body) > VerifyStripeWebhook::MAX_BODY_BYTES) {
            abort(413);
        }
        // Keep exact bytes. Never sign the framework's parsed/trimmed JSON input.
        $request->attributes->set('_stripe_raw_body', $body);

        return $next($request);
    }
}
