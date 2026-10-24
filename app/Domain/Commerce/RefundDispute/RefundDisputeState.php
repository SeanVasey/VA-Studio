<?php

namespace App\Domain\Commerce\RefundDispute;

/**
 * Pure refund/dispute state machine over complete provider snapshots.
 *
 * Each observation is a complete list of the payment's refunds and disputes, so the case state is derived from
 * the newest snapshot rather than from event arrival order. The previous accepted snapshot only constrains what
 * may change: an object cannot vanish from a complete list, its amount and currency cannot change, and its status
 * may move only along the transitions below. Anything else is attention and has no effect. No rule here decides
 * a legal consequence; effects are chosen by the configured policy elsewhere.
 */
final class RefundDisputeState
{
    public const REFUND_STATUSES = ['pending', 'requires_action', 'succeeded', 'failed', 'canceled'];

    public const DISPUTE_STATUSES = ['warning_needs_response', 'warning_under_review', 'warning_closed', 'needs_response',
        'under_review', 'won', 'lost', 'prevented'];

    public const OPEN_DISPUTES = ['warning_needs_response', 'warning_under_review', 'needs_response', 'under_review'];

    /** Refunds that still count against the paid amount. */
    public const COMMITTED_REFUNDS = ['pending', 'requires_action', 'succeeded'];

    /**
     * Permitted status changes between two observations. Observations can skip intermediate provider states, so
     * a direct jump to any later state is allowed; returning to an earlier state is not. Stripe documents that a
     * succeeded refund can later fail; that change is accepted and flagged for operator review.
     */
    public const TRANSITIONS = [
        'refund' => [
            'pending' => ['requires_action', 'succeeded', 'failed', 'canceled'],
            'requires_action' => ['pending', 'succeeded', 'failed', 'canceled'],
            'succeeded' => ['failed'],
            'failed' => [],
            'canceled' => [],
        ],
        'dispute' => [
            'warning_needs_response' => ['warning_under_review', 'warning_closed', 'needs_response', 'under_review', 'won', 'lost'],
            'warning_under_review' => ['warning_closed', 'needs_response', 'under_review', 'won', 'lost'],
            'needs_response' => ['under_review', 'won', 'lost'],
            'under_review' => ['won', 'lost'],
            'warning_closed' => [],
            'won' => [],
            'lost' => [],
            'prevented' => [],
        ],
    ];

    /**
     * @param  array<int, array{kind: string, hash: string, amount: int, currency: string, status: string}>  $prior  previous accepted snapshot
     * @param  array<int, array{kind: string, hash: string, amount: int, currency: string, status: string}>  $observed  new complete snapshot
     * @return array{valid: bool, reason: ?string, refund_state: ?string, dispute_state: ?string, succeeded_minor: ?int, pending_minor: ?int}
     */
    public static function evaluate(int $paidMinor, string $currency, array $prior, array $observed): array
    {
        $invalid = fn (string $reason): array => ['valid' => false, 'reason' => $reason, 'refund_state' => null,
            'dispute_state' => null, 'succeeded_minor' => null, 'pending_minor' => null];
        if ($paidMinor < 1 || $currency !== 'USD') {
            return $invalid('payment_shape');
        }
        $current = [];
        foreach ($observed as $object) {
            $key = self::key($object);
            if ($key === null || isset($current[$key])) {
                return $invalid('object_shape');
            }
            if ($object['currency'] !== $currency) {
                return $invalid('currency_mismatch');
            }
            $current[$key] = $object;
        }
        foreach ($prior as $object) {
            $key = self::key($object);
            if ($key === null) {
                return $invalid('object_shape');
            }
            if (! isset($current[$key])) {
                return $invalid('object_vanished');
            }
            $now = $current[$key];
            if ($now['amount'] !== $object['amount'] || $now['currency'] !== $object['currency']) {
                return $invalid('amount_changed');
            }
            if ($now['status'] !== $object['status'] && ! in_array($now['status'], self::TRANSITIONS[$object['kind']][$object['status']], true)) {
                return $invalid('status_regressed');
            }
        }
        $succeeded = 0;
        $pending = 0;
        $open = $lost = $won = $closed = false;
        foreach ($current as $object) {
            if ($object['amount'] > $paidMinor) {
                return $invalid('object_exceeds_paid');
            }
            if ($object['kind'] === 'refund') {
                if ($object['status'] === 'succeeded') {
                    $succeeded += $object['amount'];
                } elseif (in_array($object['status'], self::COMMITTED_REFUNDS, true)) {
                    $pending += $object['amount'];
                }
            } else {
                $open = $open || in_array($object['status'], self::OPEN_DISPUTES, true);
                $lost = $lost || $object['status'] === 'lost';
                $won = $won || $object['status'] === 'won';
                $closed = $closed || in_array($object['status'], ['warning_closed', 'prevented'], true);
            }
        }
        if ($succeeded + $pending > $paidMinor) {
            return $invalid('refunds_exceed_paid');
        }

        return ['valid' => true, 'reason' => null,
            'refund_state' => $succeeded === 0 ? 'none' : ($succeeded === $paidMinor ? 'full' : 'partial'),
            'dispute_state' => $open ? 'open' : ($lost ? 'lost' : ($won ? 'won' : ($closed ? 'closed' : 'none'))),
            'succeeded_minor' => $succeeded, 'pending_minor' => $pending];
    }

    private static function key(mixed $object): ?string
    {
        if (! is_array($object) || array_keys($object) !== ['kind', 'hash', 'amount', 'currency', 'status']
            || ! in_array($object['kind'], ['refund', 'dispute'], true) || ! is_string($object['hash'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $object['hash']) !== 1 || ! is_int($object['amount'])
            || $object['amount'] < 1 || $object['amount'] > 99999999 || ! is_string($object['currency'])
            || ! in_array($object['status'], $object['kind'] === 'refund' ? self::REFUND_STATUSES : self::DISPUTE_STATUSES, true)) {
            return null;
        }

        return $object['kind'].':'.$object['hash'];
    }
}
