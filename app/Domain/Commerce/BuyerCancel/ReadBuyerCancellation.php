<?php

namespace App\Domain\Commerce\BuyerCancel;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\TestBuyerOrderCancellation;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Retained proof that the buyer cancelled their own test order before it was ever sent to the payment provider.
 *
 * The proof is local: HostedCheckout commits a CheckoutIntent under the Order lock before any provider create, so no
 * intent under that lock means no create was ever issued; the checkout_intents guard then refuses one for good.
 */
final class ReadBuyerCancellation
{
    public const POLICY_VERSION = 'test-buyer-cancel-v1';

    public const PROOF = 'no_checkout_intent_buyer_owner_v1';

    public const PURPOSE = 'test_buyer_order_cancellation';

    /** Facts established under the Order lock at cancellation time (canonical key order); the database guard requires each too. */
    public const FACTS = ['checkout_intent' => 'absent', 'checkout_session' => 'absent', 'order_finalization' => 'absent',
        'unpaid_release' => 'absent', 'unsent_release' => 'absent', 'verified_payment' => 'absent'];

    /**
     * The order's buyer cancellation, if any. Its insert guard requires that the order has no checkout intent and the same
     * transaction moves the attempt's reservation to `released`, so only such an order is looked up. With $lock the caller
     * already holds the Order lock and the reservation is read for update after it (Order before reservation), so a
     * cancellation committed after this transaction's snapshot is seen.
     */
    public static function for(Order $order, bool $lock = false): ?TestBuyerOrderCancellation
    {
        $attempt = $order->attempt()->first();
        $reservation = $attempt ? InventoryReservation::whereKey($attempt->inventory_reservation_id) : null;
        $state = $reservation ? ($lock ? $reservation->lockForUpdate() : $reservation)->value('state') : null;
        if ($state !== 'released' || CheckoutIntent::where('order_id', $order->id)->exists()) {
            return null;
        }
        $cancellation = TestBuyerOrderCancellation::where('order_id', $order->id);

        return ($lock ? $cancellation->lockForUpdate() : $cancellation)->first();
    }

    public function capture(Order $order, $attempt, string $id, array $policy, CarbonImmutable $cancelledAt): array
    {
        return ['schema_version' => 1, 'purpose' => self::PURPOSE, 'mode' => 'test', 'proof' => self::PROOF,
            'policy_version' => self::POLICY_VERSION, 'public_id' => $id,
            'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'attempt_id' => $attempt->public_id, 'attempt_binding_hash' => $attempt->binding_hash, 'facts' => self::FACTS,
            'inventory_id' => $attempt->inventory_reservation_id, 'promotion_use_id' => $attempt->promotion_use_id,
            'policy' => BuyerCancelPolicy::validate($policy), 'cancelled_at' => $cancelledAt->toIso8601ZuluString()];
    }

    /** Acyclic first pass: permits only historical reconstruction of the original pending resources. */
    public function resourceDisposition(TestBuyerOrderCancellation $cancellation, Order $order, $attempt, $reservation, $use): array
    {
        $payload = app(CheckoutEvidence::class)->decrypt($cancellation->evidence_ciphertext, $cancellation->evidence_hash,
            $cancellation->canonicalization_version);
        if (! OrderRequest::uuid($cancellation->public_id)
            || $cancellation->order_id !== $order->id || $cancellation->order_attempt_id !== $attempt->id || $cancellation->mode !== 'test'
            || $cancellation->policy_version !== self::POLICY_VERSION || $cancellation->proof !== self::PROOF
            || ($payload['purpose'] ?? null) !== self::PURPOSE || ($payload['schema_version'] ?? null) !== 1
            || ($payload['proof'] ?? null) !== self::PROOF || ($payload['facts'] ?? null) !== self::FACTS
            || ($payload['order_id'] ?? null) !== $order->public_id || ($payload['order_payload_hash'] ?? null) !== $order->payload_hash
            || ($payload['attempt_id'] ?? null) !== $attempt->public_id || ($payload['attempt_binding_hash'] ?? null) !== $attempt->binding_hash
            || ($payload['public_id'] ?? null) !== $cancellation->public_id
            || ($payload['inventory_id'] ?? null) !== $reservation->id || ($payload['promotion_use_id'] ?? null) !== $use?->id
            || $cancellation->inventory_reservation_id !== $reservation->id || $cancellation->promotion_use_id !== $use?->id
            || ($payload['cancelled_at'] ?? null) !== $cancellation->cancelled_at->toIso8601ZuluString()
            || $cancellation->cancelled_at->lessThan($order->created_at)
            || $reservation->state !== 'released' || $reservation->attempt_id !== $attempt->public_id
            || $reservation->consumed_at !== null || ($use !== null && ($use->state !== 'released'
                || $use->attempt_id !== $attempt->public_id || $use->consumed_at !== null))) {
            throw new RuntimeException('Test buyer cancellation evidence changed.');
        }
        BuyerCancelPolicy::validate($payload['policy'] ?? null);

        return $payload;
    }

    public function verify(TestBuyerOrderCancellation $cancellation, Order $order): array
    {
        $attempt = $order->attempt()->sole();
        $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
        $use = $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id);
        $payload = $this->resourceDisposition($cancellation, $order, $attempt, $reservation, $use);
        $expected = $this->capture($order, $attempt, $cancellation->public_id, $payload['policy'], $cancellation->cancelled_at);
        if (CanonicalJson::encode($payload) !== CanonicalJson::encode($expected)) {
            throw new RuntimeException('Test buyer cancellation evidence changed.');
        }

        return $payload;
    }
}
