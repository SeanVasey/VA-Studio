<?php

namespace App\Http\Middleware;

use App\Domain\Merch\Fulfillment\ReceivePrintfulWebhook;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Caps the real bytes of a Printful webhook before anything buffers or parses them. Content-Length is caller-controlled
 * (omitted or understated), so the body is read through a bounded stream read, as for Stripe. This runs whatever the merch
 * flag says: the route is always mounted and unauthenticated.
 */
final class PrintfulWebhookBodyLimit
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is('webhooks/printful') || ! $request->isMethod('POST')) {
            return $next($request);
        }
        $limit = ReceivePrintfulWebhook::MAX_BODY_BYTES;
        if ((int) $request->header('Content-Length', '0') > $limit) {
            return $this->tooLarge();
        }
        $body = stream_get_contents($request->getContent(true), $limit + 1);
        if ($body === false) {
            return response()->json(['code' => 'PRINTFUL_WEBHOOK_UNREADABLE'], 503, $this->headers());
        }
        if (strlen($body) > $limit) {
            return $this->tooLarge();
        }
        // Exact bytes: the signature covers them, never the framework's parsed input.
        $request->attributes->set('_printful_raw_body', $body);

        return $next($request);
    }

    private function tooLarge(): Response
    {
        return response()->json(['code' => 'PRINTFUL_WEBHOOK_TOO_LARGE'], 413, $this->headers());
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
    }
}
