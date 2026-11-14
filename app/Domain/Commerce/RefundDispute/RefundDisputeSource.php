<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Models\Order;
use App\Domain\Grants\PaidTax\Models\PaidTaxOrderOrigin;
use Illuminate\Database\Eloquent\Builder;

/**
 * Which order chain a ledger row belongs to. The Stripe-test chain (`orders`) and the production tax chain
 * (`paid_tax_order_origins`) number their orders independently, so every order-keyed ledger query and write names the source:
 * a bare `order_id` would let one chain's order read another chain's refunds, holds and sequence.
 *
 * For the production tax chain a "line" and its "grant" are the same retained row, a `paid_tax_grant_origins` position of the
 * batch, so `order_line_id` and `license_grant_id` carry that one id.
 */
final class RefundDisputeSource
{
    public const TEST = 'test';

    public const PRODUCTION_TAX = 'production_tax';

    public static function of(object $order): string
    {
        return match (true) {
            $order instanceof Order => self::TEST,
            $order instanceof PaidTaxOrderOrigin => self::PRODUCTION_TAX,
            default => throw new RefundDisputeException('unavailable'),
        };
    }

    /** Restricts an order-keyed ledger query to one order of one chain. */
    public static function scope(Builder $query, object $order, string $alias = ''): Builder
    {
        $prefix = $alias === '' ? $query->getModel()->getTable().'.' : $alias.'.';

        return $query->where($prefix.'order_id', $order->id)->where($prefix.'order_source', self::of($order));
    }
}
