<?php

namespace App\Http\Controllers;

use App\Domain\SiteBuilder\EditorialContent;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteContentSchema;
use App\Domain\SiteBuilder\SiteContentUnavailable;
use App\Domain\SoundKits\Purchases\SoundKitCheckout;
use App\Domain\SoundKits\Purchases\SoundKitDelivery;
use App\Domain\SoundKits\Purchases\SoundKitOrders;
use App\Domain\SoundKits\Purchases\SoundKitPayments;
use App\Domain\SoundKits\Purchases\SoundKitPurchaseException;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use App\Support\CommerceRequestIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The Stripe TEST purchase lane of a sound kit (BL-13, FP-007). Every action answers 404 unless {@see SoundKitPurchasePolicy}
 * admits the lane (local, testing or staging with the flag on). The browser supplies no owner key, price, revision or license:
 * ownership is the commerce session identity, and everything purchased comes from the server-side product.
 */
final class SoundKitPurchaseController extends Controller
{
    public function store(string $slug, Request $request, CommerceRequestIdentity $owner, SoundKitOrders $orders): JsonResponse
    {
        return $this->json(function () use ($slug, $request, $owner, $orders): array {
            $key = $request->input('idempotencyKey');
            if (! is_string($key)) {
                throw new SoundKitPurchaseException('INVALID_REQUEST', 422);
            }
            $order = $orders->create($slug, $owner->forRequest($request), $key, $request->input('licenseeName'));

            return ['orderId' => $order->public_id, 'orderUrl' => '/sound-kit-orders/'.$order->public_id];
        }, 201);
    }

    public function checkout(string $order, Request $request, CommerceRequestIdentity $owner, SoundKitCheckout $checkout): JsonResponse
    {
        return $this->json(fn (): array => $checkout->start($order, $owner->forRequest($request)));
    }

    public function status(string $order, Request $request, CommerceRequestIdentity $owner, SoundKitCheckout $checkout): JsonResponse
    {
        return $this->json(fn (): array => $checkout->status($order, $owner->forRequest($request)));
    }

    /** Recovery for a payment whose webhook has not arrived: GET-only provider reads, the same verification as the receipt path. */
    public function reconcile(string $order, Request $request, CommerceRequestIdentity $owner, SoundKitCheckout $checkout): JsonResponse
    {
        return $this->json(function () use ($order, $request, $owner, $checkout): array {
            $owned = app(SoundKitOrders::class)->owned($order, $owner->forRequest($request));
            app(SoundKitPayments::class)->reconcile($owned);

            return $checkout->projection($owned, null);
        });
    }

    public function authorizeDownload(string $order, Request $request, CommerceRequestIdentity $owner, SoundKitDelivery $delivery): JsonResponse
    {
        return $this->json(fn (): array => $delivery->authorize($order, $owner->forRequest($request), $request->input('kind')), 201);
    }

    public function download(string $order, Request $request, CommerceRequestIdentity $owner, SoundKitDelivery $delivery): Response
    {
        try {
            $prepared = $delivery->redeem($order, $owner->forRequest($request), $request->input('authorizationId'), $request->input('token'));
        } catch (SoundKitPurchaseException $error) {
            return $this->error($error);
        } catch (Throwable) {
            return $this->error(new SoundKitPurchaseException('DELIVERY_UNAVAILABLE', 409));
        }
        $stream = $prepared['stream'];

        return new StreamedResponse(function () use ($stream): void {
            try {
                while (! feof($stream)) {
                    $chunk = fread($stream, 1048576);
                    if ($chunk === false) {
                        break;
                    }
                    echo $chunk;
                    flush();
                }
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => $prepared['mime'], 'Content-Length' => (string) $prepared['size'],
            'Content-Disposition' => 'attachment; filename="'.$prepared['filename'].'"', 'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff', 'Digest' => 'sha-256='.base64_encode(hex2bin($prepared['sha256']))]);
    }

    /** The owner's order page (also Stripe's return address). Arrival proves nothing; the page shows retained state only. */
    public function show(string $order, Request $request, CommerceRequestIdentity $owner, SoundKitCheckout $checkout): InertiaResponse
    {
        try {
            $projection = $checkout->status($order, $owner->forRequest($request));
        } catch (SoundKitPurchaseException) {
            abort(404);
        }
        try {
            $content = app(SiteContent::class)->current();
        } catch (SiteContentUnavailable) {
            $content = SiteContentSchema::defaults();
        }

        return Inertia::render('SoundKitOrder', ['siteContent' => app(EditorialContent::class)->chrome($content), 'order' => $projection]);
    }

    private function json(callable $operation, int $status = 200): JsonResponse
    {
        try {
            return response()->json($operation(), $status, ['Cache-Control' => 'no-store, private']);
        } catch (SoundKitPurchaseException $error) {
            return $this->error($error);
        }
    }

    private function error(SoundKitPurchaseException $error): JsonResponse
    {
        return response()->json(['code' => $error->errorCode, 'message' => $error->getMessage()], $error->status, ['Cache-Control' => 'no-store, private']);
    }
}
