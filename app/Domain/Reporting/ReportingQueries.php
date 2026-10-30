<?php

namespace App\Domain\Reporting;

use Illuminate\Database\Query\JoinClause;

/** Query fragments shared by the reporting read models. */
final class ReportingQueries
{
    /** The configured Stripe test account, or null when none is configured (then no test order is in scope). */
    public static function testAccount(): ?string
    {
        $account = config('payments.stripe.account_id');

        return is_string($account) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) === 1 ? $account : null;
    }

    /**
     * Join the order's latest accepted ledger observation as `r` to `f.order_id`. Observations are unique per order and
     * sequence, so this yields at most one row. Its `succeeded_refund_minor` is the status-aware succeeded total: a
     * refund that succeeded and later failed is not counted once a newer observation records the failure.
     */
    public static function latestObserved(JoinClause $join): void
    {
        $join->on('r.order_id', '=', 'f.order_id')->where('r.outcome', 'observed')
            ->whereRaw('r.sequence = (SELECT MAX(r2.sequence) FROM refund_dispute_observations r2 WHERE r2.order_id = f.order_id AND r2.outcome = \'observed\')');
    }
}
