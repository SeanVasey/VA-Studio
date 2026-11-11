<?php

namespace App\Domain\SoundKits\Purchases;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Checkout\CheckoutPolicy;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\QuoteException;
use App\Domain\SoundKits\Products\SoundKitProduct;
use App\Domain\SoundKits\Products\SoundKitProducts;
use App\Domain\SoundKits\Purchases\Models\SoundKitCheckoutSession;
use App\Domain\SoundKits\Purchases\Models\SoundKitContract;
use App\Domain\SoundKits\Purchases\Models\SoundKitDeliveryControl;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\Models\SoundKitPayment;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Starts the one Stripe TEST Checkout Session of a kit order through the track chain's gateway and evidence reader, and projects
 * the order's state to its owner. Provider I/O never runs inside a transaction. The session is created with the order's frozen
 * request and idempotency key, so a retry recovers the same session; the returned session is checked field by field
 * (CheckoutEvidence::session) before it is bound. A redirect back from Stripe proves nothing: payment is verified only by
 * {@see SoundKitPayments} from provider reads.
 */
final class SoundKitCheckout
{
    public function start(string $orderId, string $ownerKey): array
    {
        $order = app(SoundKitOrders::class)->owned($orderId, $ownerKey);
        $this->outsideTransactions();
        $session = SoundKitCheckoutSession::query()->where('sound_kit_order_id', $order->id)->first();
        if (! $session && ! SoundKitPayment::query()->where('sound_kit_order_id', $order->id)->exists()) {
            if ($order->checkout_before->lessThanOrEqualTo(now())) {
                throw new SoundKitPurchaseException('CHECKOUT_EXPIRED', 410);
            }
            // The kit must still be listed on the exact terms the order froze, or no money is asked for.
            $product = SoundKitProduct::query()->find($order->sound_kit_product_id);
            $products = app(SoundKitProducts::class);
            if (! $product || ! $products->listed($product) || ! hash_equals($products->termsHash($product), (string) ($order->snapshot['offer_terms_hash'] ?? ''))) {
                throw new SoundKitPurchaseException('KIT_UNAVAILABLE', 409);
            }
            $request = $order->checkout_request;
            $gateway = $this->gateway($order);
            try {
                $raw = $gateway->create($request['params'], $request['idempotency_key']);
            } catch (Throwable) {
                throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
            }
            $observed = $this->observe($raw, $request);
            try {
                DB::transaction(function () use ($order, $observed): void {
                    SoundKitOrder::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
                    if (! SoundKitCheckoutSession::query()->where('sound_kit_order_id', $order->id)->exists()) {
                        $bound = SoundKitCheckoutSession::create(['sound_kit_order_id' => $order->id, 'account_id' => $order->account_id,
                            'provider_session_id' => $observed['session_id'], 'created_at' => now()->toImmutable()->utc()->startOfSecond()]);
                        AuditEvent::recordAttributed('sound_kit.test_checkout.bound', $bound, ['order_public_id' => $order->public_id, 'test_only' => true], null);
                    }
                }, 5);
            } catch (UniqueConstraintViolationException) {
                throw new SoundKitPurchaseException('CHECKOUT_CHANGED', 409);
            }
            $session = SoundKitCheckoutSession::query()->where('sound_kit_order_id', $order->id)->firstOrFail();
            if ($session->provider_session_id !== $observed['session_id']) {
                throw new SoundKitPurchaseException('CHECKOUT_CHANGED', 409);
            }

            return $this->projection($order, $observed['status'] === 'open' ? $observed['url'] : null);
        }

        return $this->projection($order, $session ? $this->openUrl($order, $session) : null);
    }

    public function status(string $orderId, string $ownerKey): array
    {
        return $this->projection(app(SoundKitOrders::class)->owned($orderId, $ownerKey), null);
    }

    /**
     * The owner's view: state names only, never the licensee name, provider ids or any file path. `url` is a Stripe test Checkout
     * address and only while the session is open and unpaid.
     */
    public function projection(SoundKitOrder $order, ?string $url): array
    {
        $payment = SoundKitPayment::query()->where('sound_kit_order_id', $order->id)->first();
        $grant = SoundKitGrant::query()->where('sound_kit_order_id', $order->id)->first();
        $contract = $grant ? SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->first() : null;
        $control = SoundKitDeliveryControl::query()->where('sound_kit_order_id', $order->id)->first();
        $session = SoundKitCheckoutSession::query()->where('sound_kit_order_id', $order->id)->exists();
        $status = match (true) {
            $grant !== null && $contract !== null && $control?->state === 'enabled' => 'downloadable',
            $grant !== null && $contract !== null => 'awaiting_delivery',
            $grant !== null => 'awaiting_contract',
            $payment !== null => 'paid',
            $session => 'checkout_started',
            $order->checkout_before->lessThanOrEqualTo(now()) => 'expired',
            default => 'not_started',
        };

        return ['schema' => 'sound-kit-test-order-v1', 'orderId' => $order->public_id, 'testOnly' => true,
            'kit' => ['title' => $order->snapshot['product']['title'], 'slug' => $order->snapshot['product']['slug']],
            'priceMinor' => $order->price_minor, 'currency' => $order->currency, 'status' => $status,
            'checkoutUrl' => $payment === null ? $url : null,
            'deliverables' => $status === 'downloadable' ? ['archive', 'contract'] : []];
    }

    /** Re-reads an existing session (GET) and returns its URL only while it is open, or null. */
    private function openUrl(SoundKitOrder $order, SoundKitCheckoutSession $session): ?string
    {
        if (SoundKitPayment::query()->where('sound_kit_order_id', $order->id)->exists()) {
            return null;
        }
        $gateway = $this->gateway($order);
        try {
            $raw = $gateway->retrieve($session->provider_session_id);
        } catch (Throwable) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
        $observed = $this->observe($raw, $order->checkout_request);
        if ($observed['session_id'] !== $session->provider_session_id) {
            throw new SoundKitPurchaseException('CHECKOUT_CHANGED', 409);
        }

        return $observed['status'] === 'open' ? $observed['url'] : null;
    }

    private function gateway(SoundKitOrder $order): StripeCheckoutGateway
    {
        try {
            app(CheckoutPolicy::class)->current();
            $account = app(CheckoutPolicy::class)->account();
        } catch (QuoteException) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
        if ($account !== $order->account_id) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
        $gateway = app(StripeCheckoutGateway::class);
        try {
            $actual = $gateway->account();
        } catch (Throwable) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
        if (($actual['id'] ?? null) !== $order->account_id || ($actual['object'] ?? null) !== 'account') {
            throw new SoundKitPurchaseException('CHECKOUT_CHANGED', 409);
        }

        return $gateway;
    }

    private function observe(mixed $raw, array $request): array
    {
        try {
            return app(CheckoutEvidence::class)->session(is_array($raw) ? $raw : [], $request);
        } catch (QuoteException) {
            throw new SoundKitPurchaseException('CHECKOUT_CHANGED', 409);
        }
    }

    private function outsideTransactions(): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new SoundKitPurchaseException('CHECKOUT_UNAVAILABLE', 503);
        }
    }
}
