<?php

namespace App\Domain\Commerce\BuyerCancel;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CommerceAuditActor;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestBuyerOrderCancellation;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\TestUnsentOrderRelease;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerPrincipal;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

/**
 * The buyer cancels their own prepared test order before it was ever sent to the payment provider.
 *
 * Ownership is the order page's: the request's owner key (and, for a customer account, its locked principal). Lock order
 * matches HostedCheckout::start up to the Order lock (customer user/account -> audit actor -> Order), so a cancel and a
 * first checkout start serialize on the Order row and exactly one wins: start commits its CheckoutIntent before any
 * provider create, and cancel refuses once an intent exists; cancel commits its proof row with the pending -> released
 * transition, and start then refuses (and the checkout_intents guard refuses any intent). After the Order lock it
 * follows the staff never-sent release: intent -> payment/finalization/releases -> promotion campaign/use -> sorted
 * rights scopes -> reservation/claims. No provider call is made, and an order with a CheckoutIntent is never cancelled here.
 */
final class CancelTestOrder
{
    public function cancel(string $id, #[SensitiveParameter] string $ownerKey, ?User $actor = null, ?CustomerPrincipal $principal = null): array
    {
        try {
            app(BuyerCancelPolicy::class)->current();
        } catch (RuntimeException) {
            throw new QuoteException('ORDER_CANCEL_UNAVAILABLE', 403);
        }
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }
        $order = $this->owned($id, $ownerKey);
        DB::transaction(function () use ($order, $ownerKey, $actor, $principal): void {
            app(CustomerAccess::class)->lock($principal, $ownerKey, $actor);
            $actorId = app(CommerceAuditActor::class)->lock($actor);
            $locked = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals($locked->owner_key, $ownerKey)) {
                throw new QuoteException('ORDER_NOT_FOUND', 404);
            }
            // Re-read under the transaction: the policy recorded is the one admitted while the Order lock is held.
            try {
                $policy = app(BuyerCancelPolicy::class)->current();
            } catch (RuntimeException) {
                throw new QuoteException('ORDER_CANCEL_UNAVAILABLE', 403);
            }
            $existing = TestBuyerOrderCancellation::where('order_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                app(ReadBuyerCancellation::class)->verify($existing, $locked);

                return;
            }
            // The write-ahead record of every provider create. Present means the order may have reached Stripe: never cancelled here.
            if (CheckoutIntent::where('order_id', $locked->id)->lockForUpdate()->exists()) {
                throw new QuoteException('CHECKOUT_STARTED', 409);
            }
            if (VerifiedPayment::where('order_id', $locked->id)->lockForUpdate()->exists()
                || OrderFinalization::where('order_id', $locked->id)->lockForUpdate()->exists()
                || TestUnpaidRelease::where('order_id', $locked->id)->lockForUpdate()->exists()
                || TestUnsentOrderRelease::where('order_id', $locked->id)->lockForUpdate()->exists()) {
                throw new QuoteException('ORDER_NOT_CANCELLABLE', 409);
            }
            $original = app(ReadOrder::class)->verify($locked);
            $attempt = $locked->attempt()->sole();
            $resources = app(ReleaseTestOrderResources::class);
            try {
                [$reservation, $use] = $resources->lockPendingResources($attempt, $original);
            } catch (RuntimeException) {
                throw new QuoteException('ORDER_NOT_CANCELLABLE', 409);
            }
            $at = now()->toImmutable()->utc()->startOfSecond();
            $publicId = (string) Str::uuid();
            $payload = app(ReadBuyerCancellation::class)->capture($locked, $attempt, $publicId, $policy, $at);
            [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
            $cancellation = TestBuyerOrderCancellation::create(['public_id' => $publicId, 'order_id' => $locked->id,
                'order_attempt_id' => $attempt->id, 'mode' => 'test', 'policy_version' => ReadBuyerCancellation::POLICY_VERSION,
                'proof' => ReadBuyerCancellation::PROOF, 'inventory_reservation_id' => $reservation->id, 'promotion_use_id' => $use?->id,
                'cancelled_at' => $at, 'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION]);
            $resources->releaseResources($reservation, $use);
            app(ReadBuyerCancellation::class)->verify($cancellation, $locked);
            AuditEvent::recordAttributed('commerce.order.buyer_cancelled', $locked, ['order_id' => $locked->public_id,
                'cancellation_id' => $publicId, 'proof' => ReadBuyerCancellation::PROOF, 'test_only' => true], $actorId);
            app(CustomerAccess::class)->lock($principal, $ownerKey, $actor);
        }, 5);
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
        }

        return app(HostedCheckout::class)->status($id, $ownerKey);
    }

    /**
     * Display only, for the checkout projection: `cancelled` once retained, `available` while the capability is admitted
     * and the order has no intent, payment, finalization or release and its reservation is pending. cancel() decides again
     * under the locks; this answer never authorizes anything.
     */
    public function availability(Order $order, ?CheckoutIntent $intent): string
    {
        if (ReadBuyerCancellation::for($order)) {
            return 'cancelled';
        }
        if ($intent !== null || ! app(BuyerCancelPolicy::class)->admitted()
            || CheckoutIntent::where('order_id', $order->id)->exists()
            || VerifiedPayment::where('order_id', $order->id)->exists()
            || OrderFinalization::where('order_id', $order->id)->exists()
            || TestUnpaidRelease::where('order_id', $order->id)->exists()
            || TestUnsentOrderRelease::where('order_id', $order->id)->exists()) {
            return 'unavailable';
        }
        $attempt = $order->attempt()->first();
        $state = $attempt ? InventoryReservation::whereKey($attempt->inventory_reservation_id)->value('state') : null;

        return $state === 'pending' ? 'available' : 'unavailable';
    }

    private function owned(string $id, #[SensitiveParameter] string $ownerKey): Order
    {
        QuoteRequest::owner($ownerKey);
        if (! OrderRequest::uuid($id)) {
            throw new QuoteException('ORDER_NOT_FOUND', 404);
        }
        $order = Order::where('public_id', $id)->where('owner_key', $ownerKey)->first();
        if (! $order || ! hash_equals($order->owner_key, $ownerKey)) {
            throw new QuoteException('ORDER_NOT_FOUND', 404);
        }

        return $order;
    }
}
