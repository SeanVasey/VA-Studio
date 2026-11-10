<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use App\Domain\Commerce\RefundDispute\RefundDisputeSource;
use Illuminate\Database\Eloquent\Builder;

/** `forOrder($order)`: this model's rows for one order of one chain (never a bare order id). */
trait ScopesToOrder
{
    public function scopeForOrder(Builder $query, object $order): Builder
    {
        return RefundDisputeSource::scope($query, $order);
    }
}
