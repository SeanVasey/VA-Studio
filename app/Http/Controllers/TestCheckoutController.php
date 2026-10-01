<?php

namespace App\Http\Controllers;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\QuoteException;
use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SiteBuilder\SiteContentUnavailable;
use App\Support\QuoteOwner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class TestCheckoutController
{
    public function start(string $order, Request $request, QuoteOwner $owner, HostedCheckout $checkout): JsonResponse
    {
        return $this->run(function () use ($order, $request, $owner, $checkout): array {
            $this->emptyBody($request);

            return $checkout->start($order, $owner->forRequest($request));
        });
    }

    public function reconcile(string $order, Request $request, QuoteOwner $owner, HostedCheckout $checkout): JsonResponse
    {
        return $this->run(function () use ($order, $request, $owner, $checkout): array {
            $this->emptyBody($request);

            return $checkout->reconcile($order, $owner->forRequest($request));
        });
    }

    public function status(string $order, Request $request, QuoteOwner $owner, HostedCheckout $checkout): JsonResponse
    {
        return $this->run(fn () => $checkout->status($order, $owner->forRequest($request)));
    }

    public function returned(string $order, Request $request, QuoteOwner $owner, HostedCheckout $checkout): Response
    {
        // Ownership and retained evidence only. Query parameters and redirect arrival prove nothing.
        try {
            $checkout->status($order, $owner->forRequest($request));
        } catch (Throwable) {
            return response()->json(['code' => 'ORDER_NOT_FOUND'], 404, $this->headers());
        }

        try {
            $content = app(SiteContent::class)->current();
        } catch (SiteContentUnavailable) {
            // A private saved-order read stays available during a public CMS integrity outage.
            // These approved code defaults neither adopt damaged content nor recover publication.
            $content = SiteContentSchema::defaults();
        }
        $chrome = app(EditorialContent::class)->chrome($content);
        $privateMetadata = ['title' => 'Checkout status — VASEY.AUDIO', 'description' => 'View the saved test order status for this session. A browser return does not verify payment.', 'robots' => 'noindex, nofollow'];

        return Inertia::render('CheckoutReturn', ['orderId' => $order, 'siteContent' => $chrome])
            ->withViewData(['privateMetadata' => $privateMetadata])->toResponse($request)->withHeaders($this->headers());
    }

    private function emptyBody(Request $request): void
    {
        if (! $request->isJson() || $request->query->count() !== 0 || strlen($request->getContent()) > 64) {
            throw new QuoteException('INVALID_CHECKOUT_REQUEST', 422);
        }
        try {
            $body = json_decode($request->getContent(), false, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new QuoteException('INVALID_CHECKOUT_REQUEST', 422);
        }
        if (! $body instanceof stdClass || get_object_vars($body) !== []) {
            throw new QuoteException('INVALID_CHECKOUT_REQUEST', 422);
        }
    }

    private function run(callable $action): JsonResponse
    {
        try {
            return response()->json(['checkout' => $action()], 200, $this->headers());
        } catch (QuoteException $error) {
            return response()->json(['code' => $error->errorCode], $error->status, $this->headers());
        } catch (Throwable) {
            return response()->json(['code' => 'CHECKOUT_UNAVAILABLE'], 503, $this->headers());
        }
    }

    private function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Vary' => 'Cookie', 'X-Content-Type-Options' => 'nosniff', 'X-Robots-Tag' => 'noindex, nofollow', 'Referrer-Policy' => 'no-referrer'];
    }
}
