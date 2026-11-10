<?php

namespace App\Domain\Customers\DataRights;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Whether a retained order, order snapshot or contract still ties an account to its address (DD-48). One definition, two uses: the
 * deletion approval asks it for one account (`exist()`), and the retention report asks the same question of every account at once
 * (`absent()`), so the two can never disagree about which accounts' consent clock has started.
 */
final class RetainedOrders
{
    /**
     * Holder table, its column, and the account value that column equals: `id` or `owner_key` of `customer_accounts`, or `origin`
     * (the account's `production_identity_origins.public_id`, which production checkout rows carry as `buyer_origin_id`).
     * Reviews that never became an order are not holders: they are abandoned checkouts with their own 30-day period.
     */
    public const HOLDERS = [
        ['orders', 'owner_key', 'owner_key'],
        ['customer_purchase_claims', 'account_id', 'id'],
        ['production_checkout_orders', 'buyer_origin_id', 'origin'],
        ['production_tax_checkout_orders', 'buyer_origin_id', 'origin'],
        ['paid_order_origins', 'account_id', 'id'],
        ['paid_tax_order_origins', 'account_id', 'id'],
    ];

    public static function exist(int $accountId): bool
    {
        $query = DB::table('customer_accounts as ca')->where('ca.id', $accountId);
        $query->where(function (Builder $any): void {
            foreach (self::HOLDERS as $position => $holder) {
                $any->orWhereExists(fn (Builder $sub) => self::holds($sub, $holder, 'ca', 'e'.$position));
            }
        });

        return $query->exists();
    }

    /** Constrains `$query` (which ranges over `customer_accounts` as `$account`) to an account no holder ties to an order. */
    public static function absent(Builder $query, string $account): void
    {
        foreach (self::HOLDERS as $position => $holder) {
            $query->whereNotExists(fn (Builder $sub) => self::holds($sub, $holder, $account, $account.'h'.$position));
        }
    }

    private static function holds(Builder $sub, array $holder, string $account, string $alias): void
    {
        [$table, $column, $value] = $holder;
        $sub->selectRaw('1')->from($table.' as '.$alias);
        if ($value === 'origin') {
            $sub->join('production_identity_origins as '.$alias.'o', $alias.'o.public_id', '=', $alias.'.'.$column)
                ->whereColumn($alias.'o.account_id', $account.'.id');

            return;
        }
        $sub->whereColumn($alias.'.'.$column, $account.'.'.$value);
    }
}
