<?php

namespace App\Domain\Commerce\RefundResolution;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Finalization\FinalizationException;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentFinancialObservation;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Models\TestRefundResolutionRequest;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Payments\PaymentFinancialEvidence;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;

/** Acyclic retained proof. Never calls ReadOrder or reinterprets original financial/rights effects. */
final class RefundResolutionEvidence
{
    public function financial(TestPaymentExceptionEvent $event): ?TestPaymentFinancialObservation
    {
        $projection = app(PaymentFinancialEvidence::class)->project($event);
        if (($projection['state'] ?? null) !== 'observed') {
            return null;
        }
        $row = TestPaymentFinancialObservation::where('test_payment_exception_event_id', $event->id)->firstOrFail();
        $payload = app(CheckoutEvidence::class)->decrypt($row->evidence_ciphertext, $row->evidence_hash, $row->canonicalization_version);
        $source = $payload['observation']['source'];
        $amount = $payload['observation']['expected']['amount_minor'];
        if ($source['charge_after']['disputed'] || $source['disputes']['data'] !== []
            || $source['charge_after']['amount_refunded'] !== $amount || ! $source['charge_after']['refunded']) {
            return null;
        }
        $sum = 0;
        foreach ($source['refunds']['data'] as $refund) {
            // This bounded policy intentionally refuses even historical failed/canceled refund attempts.
            if ($refund['status'] !== 'succeeded' || $refund['amount'] > $amount - $sum) {
                return null;
            }
            $sum += $refund['amount'];
        }

        return $sum === $amount && $amount > 0 ? $row : null;
    }

    public function capture(string $id, TestRefundResolutionRequest $request, OrderFinalization $finalization,
        Order $order, TestPaymentExceptionEvent $event, CarbonImmutable $releasedAt): array
    {
        $financial = $this->financial($event);
        if (! $financial) {
            throw new FinalizationException('changed');
        }
        $attempt = $order->attempt()->sole();
        $requested = TestPaymentExceptionEvent::where('order_finalization_id', $finalization->id)
            ->where('request_id', $request->request_id)->where('kind', 'reconciliation_requested')->sole();
        if (! OrderRequest::uuid($id) || $finalization->outcome !== 'paid_exception' || $finalization->reason === 'released_attempt'
            || $finalization->order_id !== $order->id || $finalization->order_attempt_id !== $attempt->id
            || $request->order_finalization_id !== $finalization->id || $request->policy_version !== RefundResolutionPolicy::CONTRACT['version']
            || $requested->actor_id !== $request->actor_id || $requested->sequence !== $request->expected_sequence + 1
            || $requested->created_at->lessThan($request->created_at)
            || $event->order_finalization_id !== $finalization->id || $event->actor_id !== $request->actor_id
            || $event->request_id !== $request->request_id || $event->kind !== 'reconciliation_observed' || $event->outcome !== 'confirmed'
            || $event->sequence !== $request->expected_sequence + 2 || $event->observed_at === null
            || $event->observed_at->lessThan($requested->created_at) || $releasedAt->lessThan($event->observed_at)
            || $releasedAt->greaterThanOrEqualTo($event->observed_at->addSeconds(60))
            || $releasedAt->greaterThanOrEqualTo($request->created_at->addSeconds(120))) {
            throw new FinalizationException('changed');
        }

        return ['schema_version' => 1, 'purpose' => 'test_refunded_exception_release',
            'resolution_id' => $id, 'request_record_id' => $request->id, 'request_id' => $request->request_id,
            'request_created_at' => $request->created_at->toIso8601ZuluString(),
            'reconciliation_requested_at' => $requested->created_at->toIso8601ZuluString(),
            'expected_sequence' => $request->expected_sequence, 'actor_id' => $request->actor_id,
            'order_id' => $order->public_id, 'order_payload_hash' => $order->payload_hash,
            'finalization_id' => $finalization->public_id, 'finalization_evidence_hash' => $finalization->evidence_hash,
            'payment_evidence_hash' => $finalization->payment->evidence_hash,
            'attempt_id' => $attempt->public_id, 'inventory_reservation_id' => $attempt->inventory_reservation_id,
            'promotion_use_id' => $attempt->promotion_use_id, 'observed_event_id' => $event->id,
            'financial_evidence_hash' => $financial->evidence_hash, 'observed_at' => $event->observed_at->toIso8601ZuluString(),
            'released_at' => $releasedAt->toIso8601ZuluString(), 'policy' => RefundResolutionPolicy::CONTRACT];
    }

    public function resourceDisposition(TestRefundResolution $resolution, OrderFinalization $finalization,
        Order $order, $attempt, $reservation, $use): void
    {
        $request = TestRefundResolutionRequest::findOrFail($resolution->request_record_id);
        $event = TestPaymentExceptionEvent::findOrFail($resolution->observed_event_id);
        $expected = $this->capture($resolution->public_id, $request, $finalization, $order, $event, $resolution->released_at);
        $payload = app(CheckoutEvidence::class)->decrypt($resolution->evidence_ciphertext, $resolution->evidence_hash, $resolution->canonicalization_version);
        if (CanonicalJson::encode($payload) !== CanonicalJson::encode($expected)
            || $resolution->order_id !== $order->id || $resolution->order_finalization_id !== $finalization->id
            || $resolution->order_attempt_id !== $attempt->id || $resolution->actor_id !== $request->actor_id
            || $resolution->policy_version !== RefundResolutionPolicy::CONTRACT['version']
            || $resolution->inventory_reservation_id !== $reservation->id || $resolution->promotion_use_id !== $use?->id
            || $attempt->inventory_reservation_id !== $reservation->id || $attempt->promotion_use_id !== $use?->id
            || $reservation->state !== 'released' || $reservation->consumed_at !== null || $reservation->attempt_id !== $attempt->public_id
            || ($use !== null && ($use->state !== 'released' || $use->consumed_at !== null || $use->attempt_id !== $attempt->public_id))) {
            throw new FinalizationException('changed');
        }
    }
}
