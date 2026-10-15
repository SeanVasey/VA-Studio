<?php

namespace App\Domain\Commerce\ProductionFinancialInspection;

use App\Support\CanonicalJson;
use JsonException;

/** Pure bounded caller-envelope normalization; no provider authentication or current-balance claim. */
final class FinancialObservation
{
    public const REFUND_STATUSES = ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'];

    public const DISPUTE_STATUSES = ['warning_needs_response', 'warning_under_review', 'warning_closed',
        'needs_response', 'under_review', 'won', 'lost', 'prevented'];

    public static function capture(FinancialExpectation $expectation, array $raw): FinancialCandidate
    {
        return FinancialCandidate::capture($expectation, $raw);
    }

    /** @internal Explicit non-authority summary, also used by the candidate's guarded constructor. */
    public static function normalize(FinancialExpectation $expectation, array $raw): array
    {
        $policy = FinancialInspectionPolicy::capture();
        $nodes = $bytes = 0;
        self::plain($raw, 0, $nodes, $bytes);
        try {
            FinancialInspectionException::require(strlen(CanonicalJson::encode($raw)) <= 1048576);
        } catch (JsonException) {
            throw new FinancialInspectionException('attention');
        }
        $expected = $expectation->identity();
        self::ownAccount($raw);
        FinancialInspectionException::require(($raw['account_id'] ?? null) === $expected['provider_account']);
        $payment = self::map($raw['payment'] ?? null);
        self::ownAccount($payment);
        $gross = $expected['gross_minor'];
        $paymentId = $expected['provider_payment_id'];
        $chargeId = $payment['latest_charge'] ?? null;
        FinancialInspectionException::require(($payment['object'] ?? null) === 'payment_intent'
            && ($payment['id'] ?? null) === $paymentId && ($payment['livemode'] ?? null) === false
            && ($payment['currency'] ?? null) === 'usd' && ($payment['amount'] ?? null) === $gross
            && ($payment['amount_received'] ?? null) === $gross && ($payment['amount_capturable'] ?? null) === 0
            && ($payment['status'] ?? null) === 'succeeded' && ($payment['capture_method'] ?? null) === 'automatic'
            && self::id($chargeId, 'ch'));
        $before = self::charge(self::map($raw['charge_before'] ?? null), $paymentId, $chargeId, $gross);
        $after = self::charge(self::map($raw['charge_after'] ?? null), $paymentId, $chargeId, $gross);
        FinancialInspectionException::require($before === $after);
        $refunds = self::items(self::map($raw['refunds'] ?? null), 'refund', $paymentId, $chargeId, $gross);
        $disputes = self::items(self::map($raw['disputes'] ?? null), 'dispute', $paymentId, $chargeId, $gross);
        $refundCounts = $refundSums = array_fill_keys(self::REFUND_STATUSES, 0);
        foreach ($refunds as $item) {
            $refundCounts[$item['status']]++;
            $refundSums[$item['status']] += $item['amount'];
        }
        FinancialInspectionException::require($refundSums['succeeded'] <= $gross);
        $disputeCounts = array_fill_keys(self::DISPUTE_STATUSES, 0);
        foreach ($disputes as $item) {
            $disputeCounts[$item['status']]++;
        }
        $summary = ['schemaVersion' => 1, 'purpose' => FinancialInspectionPolicy::PURPOSE,
            'inputState' => 'consistent_candidate', 'providerAuthenticated' => false, 'inputProvenance' => 'unattested_input',
            'originalSourceKind' => $expected['source_kind'], 'currency' => 'USD', 'originalGrossMinor' => $gross,
            'chargeAmountRefundedMinor' => $after['amount_refunded'], 'chargeRefundedFlag' => $after['refunded'],
            'chargeDisputedFlag' => $after['disputed'], 'refundCountsByStatus' => $refundCounts,
            'refundSumsMinorByStatus' => $refundSums,
            'refundSucceededSumMatchesChargeAggregate' => $refundSums['succeeded'] === $after['amount_refunded'],
            'disputeCount' => count($disputes), 'disputeCountsByStatus' => $disputeCounts];
        $policy->prove();

        return $summary;
    }

    private static function plain(mixed $value, int $depth, int &$nodes, int &$bytes): void
    {
        FinancialInspectionException::require($depth <= 12 && ++$nodes <= 4096);
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $bytes += strlen((string) $key);
                FinancialInspectionException::require($bytes <= 1048576);
                self::plain($child, $depth + 1, $nodes, $bytes);
            }
        } else {
            FinancialInspectionException::require($value === null || is_string($value) || is_bool($value) || is_int($value));
            $bytes += is_string($value) ? strlen($value) : 8;
            FinancialInspectionException::require($bytes <= 1048576);
        }
    }

    private static function map(mixed $value): array
    {
        FinancialInspectionException::require(is_array($value));

        return $value;
    }

    private static function charge(array $charge, string $payment, string $id, int $gross): array
    {
        self::ownAccount($charge);
        FinancialInspectionException::require(($charge['object'] ?? null) === 'charge' && ($charge['id'] ?? null) === $id
            && ($charge['payment_intent'] ?? null) === $payment && ($charge['livemode'] ?? null) === false
            && ($charge['currency'] ?? null) === 'usd' && ($charge['amount'] ?? null) === $gross
            && ($charge['amount_captured'] ?? null) === $gross && ($charge['paid'] ?? null) === true
            && ($charge['captured'] ?? null) === true && ($charge['status'] ?? null) === 'succeeded'
            && is_int($charge['amount_refunded'] ?? null) && $charge['amount_refunded'] >= 0 && $charge['amount_refunded'] <= $gross
            && ($charge['refunded'] ?? null) === ($charge['amount_refunded'] === $gross) && is_bool($charge['disputed'] ?? null));
        $safe = array_intersect_key($charge, array_flip(['id', 'object', 'payment_intent', 'livemode', 'currency',
            'amount', 'amount_captured', 'amount_refunded', 'paid', 'captured', 'status', 'refunded', 'disputed']));
        ksort($safe, SORT_STRING);

        return $safe;
    }

    private static function items(array $list, string $kind, string $payment, string $charge, int $gross): array
    {
        FinancialInspectionException::require(($list['object'] ?? null) === 'list' && is_bool($list['has_more'] ?? null)
            && is_array($list['data'] ?? null) && array_is_list($list['data']) && count($list['data']) <= 100);
        FinancialInspectionException::require(! $list['has_more'], 'incomplete');
        $items = [];
        foreach ($list['data'] as $raw) {
            $item = self::map($raw);
            self::ownAccount($item);
            $id = $item['id'] ?? null;
            FinancialInspectionException::require(($item['object'] ?? null) === $kind
                && self::id($id, $kind === 'refund' ? 're' : 'du') && ! isset($items[$id])
                && ($item['payment_intent'] ?? null) === $payment && ($item['charge'] ?? null) === $charge
                && ($item['currency'] ?? null) === 'usd' && is_int($item['amount'] ?? null)
                && $item['amount'] >= 1 && $item['amount'] <= $gross
                && in_array($item['status'] ?? null, $kind === 'refund' ? self::REFUND_STATUSES : self::DISPUTE_STATUSES, true)
                && ($kind !== 'dispute' || ($item['livemode'] ?? null) === false)
                && (! array_key_exists('livemode', $item) || $item['livemode'] === false));
            $items[$id] = ['id' => $id, 'status' => $item['status'], 'amount' => $item['amount']];
        }
        ksort($items, SORT_STRING);

        return array_values($items);
    }

    private static function ownAccount(array $value): void
    {
        foreach (['account', 'context', 'application', 'application_fee', 'application_fee_amount', 'on_behalf_of',
            'transfer_data', 'transfer_group', 'source_transfer', 'source_transfer_reversal', 'transfer_reversal'] as $key) {
            FinancialInspectionException::require(($value[$key] ?? null) === null);
        }
    }

    public static function id(mixed $value, string $prefix): bool
    {
        return is_string($value) && preg_match('/\A'.$prefix.'_[A-Za-z0-9]{1,120}\z/D', $value) === 1;
    }
}
