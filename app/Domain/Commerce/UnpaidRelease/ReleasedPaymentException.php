<?php

namespace App\Domain\Commerce\UnpaidRelease;

use App\Domain\Commerce\Finalization\FinalizationEvidence;
use App\Domain\Commerce\Finalization\FinalizationException;
use App\Domain\Commerce\Finalization\ReadPaymentState;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\FulfillmentOutbox;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\TestUnpaidRelease;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** A distinct evidence version; historical v1 eligibility/checks are never reinterpreted. */
final class ReleasedPaymentException
{
    public function capture(Order $order, array $original, VerifiedPayment $payment, TestUnpaidRelease $release, string $id, CarbonImmutable $at): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_released_payment_exception', 'mode' => 'test',
            'finalization_id' => $id, 'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'attempt_id' => $original['attempt_id'], 'verified_payment_id' => $payment->id,
            'payment_evidence_hash' => $payment->evidence_hash, 'account_id' => $payment->account_id,
            'policy' => UnpaidReleasePolicy::CONTRACT, 'outcome' => 'paid_exception', 'reason' => 'released_attempt',
            'confirmation_observed_at' => $payment->confirmed_at->toIso8601ZuluString(),
            'eligibility_cutoff' => $original['attempt']['expires_at'], 'finalized_at' => $at->toIso8601ZuluString(),
            'release_id' => $release->public_id, 'release_evidence_hash' => $release->evidence_hash,
            'inventory_id' => $original['attempt']['inventory']['id'],
            'inventory_snapshot_hash' => $original['attempt']['inventory']['snapshot_hash'],
            'promotion_use_id' => $original['attempt']['promotion']['id'] ?? null];
    }

    /** Caller already owns Order and intent fences; the immutable release permanently vetoes grants. */
    public function retain(Order $order, array $original, VerifiedPayment $payment, TestUnpaidRelease $release): string
    {
        app(ReadUnpaidRelease::class)->verify($release, $order, $original);
        $at = now()->toImmutable()->utc()->startOfSecond();
        if ($at->lessThan($payment->confirmed_at) || $at->lessThan($release->released_at)) {
            throw new FinalizationException('retry');
        }
        $attempt = $order->attempt()->sole();
        $id = (string) Str::uuid();
        $payload = $this->capture($order, $original, $payment, $release, $id, $at);
        [$ciphertext, $hash] = app(FinalizationEvidence::class)->encrypt($payload);
        $record = OrderFinalization::create(['public_id' => $id, 'order_id' => $order->id, 'verified_payment_id' => $payment->id,
            'order_attempt_id' => $attempt->id, 'mode' => 'test', 'outcome' => 'paid_exception', 'reason' => 'released_attempt',
            'policy_version' => UnpaidReleasePolicy::CONTRACT['version'], 'confirmed_at' => $payment->confirmed_at,
            'eligibility_cutoff' => $attempt->expires_at, 'finalized_at' => $at,
            'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION]);
        FulfillmentOutbox::create(['public_id' => (string) Str::uuid(), 'order_finalization_id' => $record->id,
            'license_grant_id' => null, 'effect_key' => 'exception', 'kind' => 'order_paid_exception_v1',
            'payload' => ['schema_version' => 1, 'finalization_id' => $id, 'grant_id' => null, 'evidence_hash' => $hash],
            'state' => 'pending', 'created_at' => $at]);
        AuditEvent::recordAttributed('commerce.order.test_finalized', $record, ['order_public_id' => $order->public_id,
            'outcome' => 'paid_exception', 'reason' => 'released_attempt', 'test_only' => true], null);
        $this->verify($record, $original);

        return 'paid_exception';
    }

    public function resourceDisposition(OrderFinalization $record, Order $order, $attempt, $reservation, $use): void
    {
        $release = TestUnpaidRelease::where('order_id', $order->id)->sole();
        app(ReadUnpaidRelease::class)->resourceDisposition($release, $order, $attempt, $reservation, $use);
        $proof = app(FinalizationEvidence::class)->decrypt($record->evidence_ciphertext, $record->evidence_hash, $record->canonicalization_version);
        if ($record->order_id !== $order->id || $record->order_attempt_id !== $attempt->id
            || $record->mode !== 'test' || $record->outcome !== 'paid_exception' || $record->reason !== 'released_attempt'
            || ($proof['purpose'] ?? null) !== 'test_released_payment_exception'
            || ($proof['finalization_id'] ?? null) !== $record->public_id || ($proof['release_id'] ?? null) !== $release->public_id
            || ($proof['release_evidence_hash'] ?? null) !== $release->evidence_hash
            || ($proof['order_payload_hash'] ?? null) !== $order->payload_hash) {
            throw new FinalizationException('changed');
        }
    }

    public function verify(OrderFinalization $record, array $original): array
    {
        $order = Order::findOrFail($record->order_id);
        $attempt = $order->attempt()->sole();
        $payment = VerifiedPayment::findOrFail($record->verified_payment_id);
        app(ReadPaymentState::class)->verify($payment, $order, $original);
        $release = TestUnpaidRelease::where('order_id', $order->id)->sole();
        app(ReadUnpaidRelease::class)->verify($release, $order, $original);
        $payload = app(FinalizationEvidence::class)->decrypt($record->evidence_ciphertext, $record->evidence_hash, $record->canonicalization_version);
        $expected = $this->capture($order, $original, $payment, $release, $record->public_id, $record->finalized_at);
        if (! OrderRequest::uuid($record->public_id) || $record->policy_version !== UnpaidReleasePolicy::CONTRACT['version']
            || $record->mode !== 'test' || $record->outcome !== 'paid_exception' || $record->reason !== 'released_attempt'
            || $record->order_attempt_id !== $attempt->id || $payment->order_attempt_id !== $attempt->id
            || $release->checkout_intent_id !== $payment->checkout_intent_id || $release->checkout_session_id !== $payment->checkout_session_id
            || $release->account_id !== $payment->account_id || ! $record->confirmed_at->equalTo($payment->confirmed_at)
            || ! $record->eligibility_cutoff->equalTo($attempt->expires_at) || $record->finalized_at->lessThan($payment->confirmed_at)
            || $record->finalized_at->lessThan($release->released_at)
            || CanonicalJson::encode($payload) !== CanonicalJson::encode($expected)) {
            throw new FinalizationException('changed');
        }
        $this->resourceDisposition($record, $order, $attempt, InventoryReservation::findOrFail($attempt->inventory_reservation_id),
            $attempt->promotion_use_id === null ? null : PromotionUse::findOrFail($attempt->promotion_use_id));
        $outbox = FulfillmentOutbox::where('order_finalization_id', $record->id)->get();
        if (LicenseGrant::where('order_finalization_id', $record->id)->exists()
            || ExclusiveSale::where('order_finalization_id', $record->id)->exists() || $outbox->count() !== 1) {
            throw new FinalizationException('changed');
        }
        $event = $outbox->sole();
        $expectedEvent = ['schema_version' => 1, 'finalization_id' => $record->public_id, 'grant_id' => null, 'evidence_hash' => $record->evidence_hash];
        if (! OrderRequest::uuid($event->public_id) || $event->license_grant_id !== null || $event->effect_key !== 'exception'
            || $event->kind !== 'order_paid_exception_v1' || $event->state !== 'pending' || ! $event->created_at->equalTo($record->finalized_at)
            || CanonicalJson::encode($event->payload) !== CanonicalJson::encode($expectedEvent)) {
            throw new FinalizationException('changed');
        }

        return $payload;
    }
}
