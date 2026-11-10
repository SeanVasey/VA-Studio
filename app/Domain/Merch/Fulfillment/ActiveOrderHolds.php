<?php

namespace App\Domain\Merch\Fulfillment;

use Illuminate\Support\Facades\DB;

/** Whether an order is under an unreleased refund or dispute hold (`refund_dispute_holds`). Goods are not sent while one stands. */
class ActiveOrderHolds
{
    public function exist(int $orderId): bool
    {
        return DB::table('refund_dispute_holds')->where('order_id', $orderId)->whereNull('released_at')->exists();
    }
}
