<?php

namespace App\Domain\Memberships\Production;

/** Complete bounded retained-period audit. It verifies facts; it does not confer writer authority. */
final class MembershipLedgerAudit
{
    public static function verify(array $period, array $events, array $redemptions, MembershipInvoiceBinding $invoice,
        MembershipPolicyBinding $policy, int $originId): array
    {
        MembershipException::require(array_is_list($events) && array_is_list($redemptions) && count($events) >= 1
            && count($events) <= MembershipPolicy::MAX_EVENTS && count($redemptions) < MembershipPolicy::MAX_EVENTS, 'technical_bound');
        foreach ($events as $event) {
            MembershipException::require(is_array($event), 'tampered');
            MembershipPeriodRecords::verifyEvent($event);
            self::integer($event['sequence'], MembershipPolicy::MAX_EVENTS);
        }
        usort($events, fn (array $left, array $right): int => self::integer($left['sequence'], MembershipPolicy::MAX_EVENTS)
            <=> self::integer($right['sequence'], MembershipPolicy::MAX_EVENTS));
        $award = MembershipPeriodRecords::verifyFirstAward($period, $events[0], $invoice, $policy, $originId);
        $reservations = [];
        $requestKeys = [];
        foreach ($redemptions as $row) {
            MembershipException::require(is_array($row), 'tampered');
            $payload = MembershipReservationRecords::verify($row, $period, $invoice, $policy);
            MembershipException::require(! isset($reservations[$row['id']]) && ! isset($requestKeys[$row['request_key_hash']]), 'duplicate_redemption');
            $requestKeys[$row['request_key_hash']] = true;
            $reservations[$row['id']] = ['record' => $row, 'payload' => $payload, 'reserve' => null, 'terminal' => null];
        }
        $balances = ['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 0];
        $ids = $keys = [];
        $prior = null;
        foreach ($events as $index => $event) {
            MembershipException::require($event['period_id'] === $period['id'] && self::integer($event['sequence'], MembershipPolicy::MAX_EVENTS) === $index + 1
                && ! isset($ids[$event['id']]) && ! isset($keys[$event['key_hash']]), 'invalid_credit_chain');
            $ids[$event['id']] = $keys[$event['key_hash']] = true;
            foreach (['key_hash', 'request_hash', 'prior_seal'] as $field) {
                MembershipException::require(is_string($event[$field]), 'tampered');
                MembershipValues::hash($event[$field]);
            }
            $amount = self::integer($event['amount']);
            MembershipException::require($amount > 0, 'invalid_credit_amount');
            foreach ($balances as $balance => $value) {
                MembershipException::require(self::integer($event['before_'.$balance]) === $value, 'invalid_credit_balance');
            }
            MembershipException::require($event['prior_seal'] === ($prior['seal'] ?? str_repeat('0', 64))
                && ($prior === null || $event['created_at'] >= $prior['created_at']), 'invalid_credit_chain');
            foreach (['grant_origin_id', 'grant_receipt_hash', 'grant_purpose'] as $column) {
                MembershipException::require($event[$column] === null, 'unsupported_credit_history');
            }
            if ($index === 0) {
                $balances['available'] = $policy->creditAllowance;
            } elseif ($event['kind'] === 'reserve') {
                MembershipException::require($policy->supports('reserve') && $policy->redemptionTerms !== null, 'unsupported_policy');
                $id = $event['redemption_id'];
                MembershipException::require(is_string($id) && isset($reservations[$id]), 'orphan_reservation');
                $row = $reservations[$id]['record'];
                MembershipException::require($reservations[$id]['reserve'] === null && $event['reservation_event_id'] === null
                    && $amount === self::integer($row['credit_amount']) && $event['created_at'] === $row['created_at']
                    && $event['created_at'] < $row['honor_deadline']
                    && ($period['credit_expires_at'] === null || $event['created_at'] < $period['credit_expires_at'])
                    && $event['key_hash'] === MembershipReservationRecords::reserveKey($row)
                    && $event['request_hash'] === $row['intent_hash'], 'invalid_reservation');
                MembershipException::require($balances['available'] >= $amount, 'insufficient_credit');
                $balances['available'] -= $amount;
                $balances['reserved'] += $amount;
                $reservations[$id]['reserve'] = $event;
            } elseif ($event['kind'] === 'release') {
                MembershipException::require($policy->supports('release') && $policy->redemptionTerms !== null, 'unsupported_policy');
                $id = $event['redemption_id'];
                MembershipException::require(is_string($id) && isset($reservations[$id])
                    && $reservations[$id]['reserve'] !== null && $reservations[$id]['terminal'] === null, 'invalid_terminal');
                $row = $reservations[$id]['record'];
                $reserve = $reservations[$id]['reserve'];
                MembershipException::require($event['reservation_event_id'] === $reserve['id']
                    && $amount === self::integer($row['credit_amount']) && $event['created_at'] >= $row['honor_deadline']
                    && $event['key_hash'] === MembershipReservationRecords::releaseKey($row)
                    && $event['request_hash'] === MembershipReservationRecords::releaseRequest($period, $row, $reserve), 'invalid_terminal');
                MembershipException::require($balances['reserved'] >= $amount, 'invalid_credit_balance');
                $balances['reserved'] -= $amount;
                $balances[$policy->redemptionTerms->releaseDestination($event['created_at'], $period['credit_expires_at'])] += $amount;
                $reservations[$id]['terminal'] = $event;
            } elseif ($event['kind'] === 'expire') {
                MembershipException::require($policy->supports('expire') && $policy->redemptionTerms !== null, 'unsupported_policy');
                MembershipException::require($event['redemption_id'] === null && $event['reservation_event_id'] === null
                    && $period['credit_expires_at'] !== null && $event['created_at'] >= $period['credit_expires_at']
                    && $amount === $balances['available']
                    && $event['key_hash'] === MembershipReservationRecords::expireKey($period)
                    && $event['request_hash'] === MembershipReservationRecords::expireRequest($period, $amount), 'invalid_expiry');
                $balances['available'] = 0;
                $balances['expired'] += $amount;
            } else {
                // A C4 consume must later have its complete original/activation audit, not just shaped grant columns.
                throw new MembershipException('unsupported_credit_history');
            }
            foreach ($balances as $balance => $value) {
                MembershipException::require(self::integer($event['after_'.$balance]) === $value, 'invalid_credit_balance');
            }
            MembershipException::require(array_sum($balances) === $policy->creditAllowance && $balances['consumed'] === 0, 'invalid_credit_balance');
            $prior = $event;
        }
        foreach ($reservations as $reservation) {
            MembershipException::require($reservation['reserve'] !== null, 'orphan_redemption');
        }

        return ['award' => $award, 'period' => $period, 'policy_hash' => $policy->policyHash,
            'buyer_binding' => $invoice->originalBuyerBinding, 'balances' => $balances, 'tail' => $prior, 'reservations' => $reservations];
    }

    /** Native PDO integers may be strings; noncanonical forms, coercions and overflow are refused. */
    public static function integer(mixed $value, int $maximum = MembershipPolicy::MAX_CREDITS): int
    {
        MembershipException::require(is_int($value) || (is_string($value) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) === 1), 'tampered');
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $maximum]]);
        MembershipException::require($integer !== false, 'tampered');

        return $integer;
    }
}
