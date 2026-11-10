<?php

namespace App\Domain\Merch\Fulfillment;

use Illuminate\Support\Facades\DB;

/**
 * Whether goods must not be sent for an order: an unreleased refund or dispute hold, or ANY refund or dispute object observed for it
 * (`refund_dispute_objects`, regardless of status). A full refund records allocations and review effects but no hold, and merch has no
 * grant line to suspend, so the object itself is the signal. This fails closed: a partly refunded order also waits for an operator.
 */
class ActiveOrderHolds
{
    public function exist(int $orderId): bool
    {
        return DB::table('refund_dispute_holds')->where('order_id', $orderId)->whereNull('released_at')->exists()
            || DB::table('refund_dispute_objects')->where('order_id', $orderId)->exists();
    }
}
