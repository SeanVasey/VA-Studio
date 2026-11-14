<?php

namespace App\Domain\Commerce\UnpaidRelease;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\TestUnpaidReleaseEvent;
use App\Domain\Commerce\Models\TestUnsentOrderRelease;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Retained proof that a test order was released without ever being sent to the payment provider.
 *
 * The proof is local: HostedCheckout commits a CheckoutIntent under the Order lock before any provider create,
 * so no intent means no create was ever issued; a closed attempt window means none can be issued later.
 */
final class ReadUnsentRelease
{
    public const POLICY_VERSION = 'test-unsent-release-v1';

    public const PROOF = 'no_checkout_intent_closed_window_v1';

    public const PURPOSE = 'test_unsent_order_release';

    /**
     * The order's never-sent release, if any. Its insert guard requires that the order has no checkout intent, and the
     * same transaction moves the attempt's reservation to `released`; the checkout intent guard then refuses any intent.
     * So only an order with a released reservation and no intent is looked up. An unexplained released reservation
     * still fails historical verification, so the shortcut fails closed.
     */
    public static function for(Order $order, bool $lock = false): ?TestUnsentOrderRelease
    {
        $attempt = $order->attempt()->first();
        // With $lock the caller already holds the Order lock (HostedCheckout::start). Reading the reservation for update
        // after it keeps the release service's order (Order before reservation) and sees a release committed after this
        // transaction's snapshot, which a plain REPEATABLE READ read could miss.
        $reservation = $attempt ? InventoryReservation::whereKey($attempt->inventory_reservation_id) : null;
        $state = $reservation ? ($lock ? $reservation->lockForUpdate() : $reservation)->value('state') : null;
        if ($state !== 'released' || CheckoutIntent::where('order_id', $order->id)->exists()) {
            return null;
        }
        $release = TestUnsentOrderRelease::where('order_id', $order->id);

        return ($lock ? $release->lockForUpdate() : $release)->first();
    }

    /** Facts established under the Order lock at release time (canonical key order); the database guard requires each too. */
    public const FACTS = ['checkout_intent' => 'absent', 'checkout_session' => 'absent', 'checkout_window' => 'closed',
        'order_finalization' => 'absent', 'unpaid_release' => 'absent', 'verified_payment' => 'absent'];

    public function capture(Order $order, $attempt, string $id, string $requestId, int $actorId, int $eventId,
        array $policy, CarbonImmutable $releasedAt): array
    {
        return ['schema_version' => 1, 'purpose' => self::PURPOSE, 'mode' => 'test', 'proof' => self::PROOF,
            'policy_version' => self::POLICY_VERSION, 'public_id' => $id,
            'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'attempt_id' => $attempt->public_id, 'attempt_binding_hash' => $attempt->binding_hash,
            'checkout_window_closed_at' => $attempt->expires_at->toIso8601ZuluString(), 'facts' => self::FACTS,
            'inventory_id' => $attempt->inventory_reservation_id, 'promotion_use_id' => $attempt->promotion_use_id,
            'request_id' => $requestId, 'actor_id' => $actorId, 'event_id' => $eventId,
            'policy' => UnpaidReleasePolicy::validate($policy), 'released_at' => $releasedAt->toIso8601ZuluString()];
    }

    /** Acyclic first pass: permits only historical reconstruction of the original pending resources. */
    public function resourceDisposition(TestUnsentOrderRelease $release, Order $order, $attempt, $reservation, $use): array
    {
        $payload = app(CheckoutEvidence::class)->decrypt($release->evidence_ciphertext, $release->evidence_hash, $release->canonicalization_version);
        if (! OrderRequest::uuid($release->public_id) || ! OrderRequest::uuid($release->request_id)
            || $release->order_id !== $order->id || $release->order_attempt_id !== $attempt->id || $release->mode !== 'test'
            || $release->policy_version !== self::POLICY_VERSION || $release->proof !== self::PROOF
            || ($payload['purpose'] ?? null) !== self::PURPOSE || ($payload['schema_version'] ?? null) !== 1
            || ($payload['proof'] ?? null) !== self::PROOF || ($payload['facts'] ?? null) !== self::FACTS
            || ($payload['order_id'] ?? null) !== $order->public_id || ($payload['order_payload_hash'] ?? null) !== $order->payload_hash
            || ($payload['attempt_id'] ?? null) !== $attempt->public_id || ($payload['attempt_binding_hash'] ?? null) !== $attempt->binding_hash
            || ($payload['public_id'] ?? null) !== $release->public_id || ($payload['request_id'] ?? null) !== $release->request_id
            || ($payload['inventory_id'] ?? null) !== $reservation->id || ($payload['promotion_use_id'] ?? null) !== $use?->id
            || $release->inventory_reservation_id !== $reservation->id || $release->promotion_use_id !== $use?->id
            || ! $release->checkout_window_closed_at->equalTo($attempt->expires_at)
            || ($payload['checkout_window_closed_at'] ?? null) !== $attempt->expires_at->toIso8601ZuluString()
            || ($payload['released_at'] ?? null) !== $release->released_at->toIso8601ZuluString()
            || $release->released_at->lessThan($attempt->expires_at) || $release->released_at->lessThan($order->created_at)
            || $reservation->state !== 'released' || $reservation->attempt_id !== $attempt->public_id
            || $reservation->consumed_at !== null || ($use !== null && ($use->state !== 'released'
                || $use->attempt_id !== $attempt->public_id || $use->consumed_at !== null))) {
            throw new RuntimeException('Test unsent release evidence changed.');
        }
        UnpaidReleasePolicy::validate($payload['policy'] ?? []);

        return $payload;
    }

    public function verify(TestUnsentOrderRelease $release, Order $order): array
    {
        $attempt = $order->attempt()->sole();
        $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
        $use = $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id);
        $payload = $this->resourceDisposition($release, $order, $attempt, $reservation, $use);
        $event = TestUnpaidReleaseEvent::findOrFail($release->event_id);
        $requested = TestUnpaidReleaseEvent::where('order_id', $order->id)->where('request_id', $release->request_id)->where('kind', 'requested')->sole();
        $expected = $this->capture($order, $attempt, $release->public_id, $release->request_id, $release->actor_id,
            $event->id, $payload['policy'], $release->released_at);
        if ($event->order_id !== $order->id || $event->request_id !== $release->request_id || $event->actor_id !== $release->actor_id
            || $event->kind !== 'observed' || $event->outcome !== 'released' || ! $event->created_at->equalTo($release->released_at)
            || $event->observed_at === null || ! $event->observed_at->equalTo($release->released_at)
            || $requested->actor_id !== $release->actor_id || $requested->outcome !== 'pending'
            || $requested->sequence + 1 !== $event->sequence || $requested->created_at->greaterThan($release->released_at)
            || CanonicalJson::encode($payload) !== CanonicalJson::encode($expected)) {
            throw new RuntimeException('Test unsent release evidence changed.');
        }

        return $payload;
    }
}
