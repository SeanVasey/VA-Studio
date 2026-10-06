<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentFinancialObservation;
use App\Support\CanonicalJson;
use RuntimeException;

/** Bounded provider observations, never a refund, release or entitlement decision. */
final class PaymentFinancialEvidence
{
    public function capture(array $source, array $expected): array
    {
        $payment = $source['payment'] ?? [];
        if (($source['account_id'] ?? null) !== $expected['account_id']) {
            $this->invalid();
        }
        foreach ($expected['payment'] as $key => $value) {
            if (CanonicalJson::encode($payment[$key] ?? null) !== CanonicalJson::encode($value)) {
                $this->invalid();
            }
        }
        $this->ownAccount($payment);
        $id = $payment['latest_charge'] ?? null;
        if (! $this->id($id, 'ch') || ($payment['status'] ?? null) !== 'succeeded') {
            $this->invalid();
        }
        $amount = $expected['amount_minor'];
        $charge = $this->charge($source['charge_before'] ?? [], $payment['id'], $id, $amount);
        $after = $this->charge($source['charge_after'] ?? [], $payment['id'], $id, $amount);
        if (CanonicalJson::encode($charge) !== CanonicalJson::encode($after)) {
            $this->invalid();
        }
        $refunds = $this->items($source['refunds'] ?? [], 'refund', $payment['id'], $id);
        $disputes = $this->items($source['disputes'] ?? [], 'dispute', $payment['id'], $id);
        foreach ($refunds as $refund) {
            if ($refund['amount'] > $amount) {
                $this->invalid();
            }
        }
        // Preserve the provider aggregate separately from refund statuses; these
        // separate GETs do not establish a settled balance or atomic snapshot.
        if (($refunds === [] && $charge['amount_refunded'] !== 0) || ($charge['disputed'] && $disputes === [])) {
            $this->invalid();
        }
        $safePayment = array_intersect_key($payment, array_flip([...array_keys($expected['payment']), 'latest_charge']));

        return ['state' => 'observed', 'expected' => $expected, 'source' => ['account_id' => $source['account_id'],
            'payment' => $safePayment, 'charge_before' => $charge, 'charge_after' => $after,
            'refunds' => ['object' => 'list', 'has_more' => false, 'data' => $refunds],
            'disputes' => ['object' => 'list', 'has_more' => false, 'data' => $disputes]]];
    }

    public function retain(TestPaymentExceptionEvent $event, OrderFinalization $finalization, array $observation): void
    {
        $payload = ['schema_version' => 1, 'purpose' => 'test_payment_financial_observation', 'event_id' => $event->id,
            'sequence' => $event->sequence, 'finalization_id' => $finalization->public_id,
            'payment_id' => $finalization->verified_payment_id, 'observed_at' => $event->observed_at->toIso8601ZuluString(),
            'observation' => $observation];
        [$ciphertext, $hash] = app(CheckoutEvidence::class)->encrypt($payload);
        TestPaymentFinancialObservation::create(['test_payment_exception_event_id' => $event->id, 'state' => $observation['state'],
            'evidence_ciphertext' => $ciphertext, 'evidence_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
            'observed_at' => $event->observed_at]);
    }

    public function project(TestPaymentExceptionEvent $event): ?array
    {
        // The order/event fence is already held by the caller. Read current sidecar state even with an older RR snapshot.
        $row = TestPaymentFinancialObservation::where('test_payment_exception_event_id', $event->id)->lockForUpdate()->first();
        if (! $row) {
            return null;
        }
        $finalization = OrderFinalization::findOrFail($event->order_finalization_id);
        $payload = app(CheckoutEvidence::class)->decrypt($row->evidence_ciphertext, $row->evidence_hash, $row->canonicalization_version);
        if (($payload['schema_version'] ?? null) !== 1 || ($payload['purpose'] ?? null) !== 'test_payment_financial_observation'
            || ($payload['event_id'] ?? null) !== $event->id || ($payload['sequence'] ?? null) !== $event->sequence
            || ($payload['finalization_id'] ?? null) !== $finalization->public_id
            || ($payload['payment_id'] ?? null) !== $finalization->verified_payment_id
            || ($payload['observed_at'] ?? null) !== $event->observed_at?->toIso8601ZuluString()
            || ! $row->observed_at->equalTo($event->observed_at) || ($payload['observation']['state'] ?? null) !== $row->state) {
            throw new RuntimeException('Invalid retained financial observation.');
        }
        $observation = $payload['observation'];
        if ($row->state !== 'observed') {
            if (! in_array($row->state, ['incomplete', 'attention', 'unavailable'], true) || $observation !== ['state' => $row->state]) {
                throw new RuntimeException('Invalid retained financial observation.');
            }

            return ['state' => $row->state];
        }
        $payment = $finalization->payment;
        $original = app(CheckoutEvidence::class)->decrypt($payment->evidence_ciphertext, $payment->evidence_hash, $payment->canonicalization_version);
        $expected = ['account_id' => $original['account_id'], 'payment' => $original['payment'], 'amount_minor' => $original['amount_minor']];
        if (CanonicalJson::encode($observation['expected'] ?? null) !== CanonicalJson::encode($expected)) {
            throw new RuntimeException('Invalid retained financial identity.');
        }
        $validated = $this->capture($observation['source'], $expected);
        if (CanonicalJson::encode($validated) !== CanonicalJson::encode($observation)) {
            throw new RuntimeException('Invalid retained financial observation.');
        }
        $source = $validated['source'];
        $refunds = $source['refunds']['data'];
        $disputes = $source['disputes']['data'];

        return ['state' => 'observed', 'currency' => 'USD', 'refundedMinor' => $source['charge_after']['amount_refunded'],
            'refundCount' => count($refunds), 'refundStatuses' => $this->statuses($refunds),
            'disputeCount' => count($disputes), 'disputeStatuses' => $this->statuses($disputes)];
    }

    private function charge(array $charge, string $payment, string $id, int $amount): array
    {
        $this->ownAccount($charge);
        if (($charge['object'] ?? null) !== 'charge' || ($charge['id'] ?? null) !== $id || ($charge['payment_intent'] ?? null) !== $payment
            || ($charge['livemode'] ?? null) !== false || ($charge['currency'] ?? null) !== 'usd'
            || ($charge['amount'] ?? null) !== $amount || ($charge['amount_captured'] ?? null) !== $amount
            || ($charge['paid'] ?? null) !== true || ($charge['captured'] ?? null) !== true || ($charge['status'] ?? null) !== 'succeeded'
            || ! is_int($charge['amount_refunded'] ?? null) || $charge['amount_refunded'] < 0 || $charge['amount_refunded'] > $amount
            || ($charge['refunded'] ?? null) !== ($charge['amount_refunded'] === $amount) || ! is_bool($charge['disputed'] ?? null)) {
            $this->invalid();
        }

        return array_intersect_key($charge, array_flip(['id', 'object', 'payment_intent', 'livemode', 'currency', 'amount',
            'amount_captured', 'amount_refunded', 'paid', 'captured', 'status', 'refunded', 'disputed']));
    }

    private function items(array $list, string $kind, string $payment, string $charge): array
    {
        if (($list['object'] ?? null) !== 'list' || ! is_bool($list['has_more'] ?? null)
            || ! is_array($list['data'] ?? null) || ! array_is_list($list['data']) || count($list['data']) > 100) {
            $this->invalid();
        }
        if ($list['has_more']) {
            throw new PaymentVerificationException('financial_incomplete');
        }
        $safe = [];
        foreach ($list['data'] as $item) {
            if (! is_array($item)) {
                $this->invalid();
            }
            $this->ownAccount($item);
            $id = $item['id'] ?? null;
            $statuses = $kind === 'refund' ? ['pending', 'requires_action', 'succeeded', 'failed', 'canceled']
                : ['warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost', 'prevented'];
            if (($item['object'] ?? null) !== $kind || ! $this->id($id, $kind === 'refund' ? 're' : 'du') || isset($safe[$id])
                || ($item['payment_intent'] ?? null) !== $payment || ($item['charge'] ?? null) !== $charge
                || ($item['currency'] ?? null) !== 'usd' || ! is_int($item['amount'] ?? null) || $item['amount'] < 1 || $item['amount'] > 99999999
                || ! in_array($item['status'] ?? null, $statuses, true)
                || ($kind === 'dispute' && ($item['livemode'] ?? null) !== false)
                || (array_key_exists('livemode', $item) && $item['livemode'] !== false)) {
                $this->invalid();
            }
            $safe[$id] = array_intersect_key($item, array_flip(['id', 'object', 'payment_intent', 'charge', 'currency', 'amount', 'status', 'livemode']));
        }
        ksort($safe, SORT_STRING);

        return array_values($safe);
    }

    private function ownAccount(array $object): void
    {
        foreach (['account', 'context', 'application', 'application_fee', 'application_fee_amount', 'on_behalf_of', 'transfer_data', 'transfer_group', 'source_transfer', 'source_transfer_reversal', 'transfer_reversal'] as $key) {
            if (($object[$key] ?? null) !== null) {
                $this->invalid();
            }
        }
    }

    private function statuses(array $items): array
    {
        $statuses = array_values(array_unique(array_column($items, 'status')));
        sort($statuses, SORT_STRING);

        return $statuses;
    }

    private function id(mixed $id, string $prefix): bool
    {
        return is_string($id) && preg_match('/\A'.$prefix.'_[A-Za-z0-9]{1,120}\z/D', $id) === 1;
    }

    private function invalid(): never
    {
        throw new PaymentVerificationException('financial_attention');
    }
}
