<?php

namespace App\Domain\Commerce\UnpaidRelease;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\TestUnpaidReleaseEvent;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Retained proof, independent of today's policy switch and later payment observations. */
final class ReadUnpaidRelease
{
    public function capture(Order $order, CheckoutIntent $intent, CheckoutSession $session, string $id,
        string $requestId, int $actorId, int $eventId, array $policy, array $observation,
        CarbonImmutable $observedAt, CarbonImmutable $releasedAt): array
    {
        $attempt = $order->attempt()->sole();

        return ['schema_version' => 1, 'purpose' => 'test_unpaid_resource_release', 'mode' => 'test',
            'public_id' => $id, 'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'attempt_id' => $attempt->public_id, 'attempt_binding_hash' => $attempt->binding_hash,
            'intent_id' => $intent->public_id, 'intent_request_hash' => $intent->request_hash,
            'session_id' => $session->provider_session_id, 'session_evidence_hash' => $session->evidence_hash,
            'account_id' => $intent->account_id, 'inventory_id' => $attempt->inventory_reservation_id,
            'promotion_use_id' => $attempt->promotion_use_id, 'request_id' => $requestId,
            'actor_id' => $actorId, 'event_id' => $eventId, 'policy' => UnpaidReleasePolicy::validate($policy),
            'observation' => $observation, 'observed_at' => $observedAt->toIso8601ZuluString(),
            'released_at' => $releasedAt->toIso8601ZuluString()];
    }

    /** Acyclic first pass permits only historical reconstruction of the original pending resources. */
    public function resourceDisposition(TestUnpaidRelease $release, Order $order, $attempt, $reservation, $use): array
    {
        $payload = app(CheckoutEvidence::class)->decrypt($release->evidence_ciphertext, $release->evidence_hash, $release->canonicalization_version);
        if (! OrderRequest::uuid($release->public_id) || ! OrderRequest::uuid($release->request_id)
            || $release->order_id !== $order->id || $release->order_attempt_id !== $attempt->id
            || $release->mode !== 'test' || $release->policy_version !== UnpaidReleasePolicy::CONTRACT['version']
            || ($payload['purpose'] ?? null) !== 'test_unpaid_resource_release' || ($payload['schema_version'] ?? null) !== 1
            || ($payload['order_id'] ?? null) !== $order->public_id || ($payload['order_payload_hash'] ?? null) !== $order->payload_hash
            || ($payload['attempt_id'] ?? null) !== $attempt->public_id || ($payload['attempt_binding_hash'] ?? null) !== $attempt->binding_hash
            || ($payload['public_id'] ?? null) !== $release->public_id || ($payload['request_id'] ?? null) !== $release->request_id
            || ($payload['inventory_id'] ?? null) !== $reservation->id || ($payload['promotion_use_id'] ?? null) !== $use?->id
            || $release->inventory_reservation_id !== $reservation->id || $release->promotion_use_id !== $use?->id
            || ($payload['released_at'] ?? null) !== $release->released_at->toIso8601ZuluString()
            || $release->released_at->lessThan($order->created_at)
            || $reservation->state !== 'released' || $reservation->attempt_id !== $attempt->public_id
            || $reservation->consumed_at !== null || ($use !== null && ($use->state !== 'released'
                || $use->attempt_id !== $attempt->public_id || $use->consumed_at !== null))) {
            throw new RuntimeException('Test unpaid release evidence changed.');
        }
        UnpaidReleasePolicy::validate($payload['policy'] ?? []);

        return $payload;
    }

    public function verify(TestUnpaidRelease $release, Order $order, array $original): array
    {
        $attempt = $order->attempt()->sole();
        $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
        $use = $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id);
        $payload = $this->resourceDisposition($release, $order, $attempt, $reservation, $use);
        $intent = CheckoutIntent::findOrFail($release->checkout_intent_id);
        $session = CheckoutSession::findOrFail($release->checkout_session_id);
        $request = app(CheckoutEvidence::class)->verifyIntent($intent, $order, $original);
        app(UnpaidReleaseEvidence::class)->validate($payload['observation'] ?? [], $request);
        $observedAt = CarbonImmutable::parse($payload['observed_at']);
        $event = TestUnpaidReleaseEvent::findOrFail($release->event_id);
        $requested = TestUnpaidReleaseEvent::where('order_id', $order->id)->where('request_id', $release->request_id)->where('kind', 'requested')->sole();
        $expected = $this->capture($order, $intent, $session, $release->public_id, $release->request_id, $release->actor_id,
            $event->id, $payload['policy'], $payload['observation'], $observedAt, $release->released_at);
        if ($intent->order_id !== $order->id || $intent->order_attempt_id !== $attempt->id
            || $session->checkout_intent_id !== $intent->id || $session->account_id !== $intent->account_id || $session->mode !== 'test'
            || ($payload['observation']['before']['id'] ?? null) !== $session->provider_session_id
            || $release->account_id !== $intent->account_id || $observedAt->lessThan($session->created_at)
            || $release->released_at->lessThan($observedAt) || $event->order_id !== $order->id
            || $event->request_id !== $release->request_id || $event->actor_id !== $release->actor_id
            || $event->kind !== 'observed' || $event->outcome !== 'released' || ! $event->created_at->equalTo($release->released_at)
            || $event->observed_at === null || ! $event->observed_at->equalTo($observedAt)
            || $requested->actor_id !== $release->actor_id || $requested->outcome !== 'pending'
            || $requested->sequence + 1 !== $event->sequence || $requested->created_at->greaterThan($observedAt)
            || CanonicalJson::encode($payload) !== CanonicalJson::encode($expected)) {
            throw new RuntimeException('Test unpaid release evidence changed.');
        }

        // Later verified money is retained separately. Its presence cannot invalidate historical release proof.
        return $payload;
    }
}
