<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\CustomerAccessException;
use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\CustomerPurchaseClaimPolicy;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Domain\Customers\PurchaseAccess;
use SensitiveParameter;

/** Bounded current-principal history of original orders and explicitly saved purchases. */
final class ReadOwnedTestOrders
{
    public const LIMIT = 20;

    private const FIELDS = ['id', 'createdAt', 'testOnly', 'payable', 'currency', 'totalMinor',
        'status', 'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus'];

    public function handle(#[SensitiveParameter] string $ownerKey, ?string $before = null, ?CustomerPrincipal $principal = null): array
    {
        QuoteRequest::owner($ownerKey);
        $query = Order::where(function ($query) use ($ownerKey, $principal): void {
            $query->where('owner_key', $ownerKey);
            if ($principal && app(CustomerPurchaseClaimPolicy::class)->enabled()) {
                app(CustomerAccess::class)->current($principal);
                if (! hash_equals($ownerKey, $principal->ownerKey)) {
                    throw new CustomerAccessException;
                }
                $query->orWhereIn('id', CustomerPurchaseClaim::where('account_id', $principal->accountId)->select('order_id'));
            }
        });
        if ($before !== null) {
            if (! OrderRequest::uuid($before)) {
                throw new QuoteException('ORDER_HISTORY_CURSOR_INVALID', 422);
            }
            $anchor = (clone $query)->where('public_id', $before)->first();
            if (! $anchor) {
                throw new QuoteException('ORDER_HISTORY_CURSOR_INVALID', 422);
            }
            app(PurchaseAccess::class)->assertOrder($anchor, $ownerKey, $principal);
            $query->where(fn ($page) => $page->where('created_at', '<', $anchor->created_at)
                ->orWhere(fn ($tie) => $tie->where('created_at', $anchor->created_at)->where('id', '<', $anchor->id)));
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT + 1)->get();
        $orders = [];
        foreach ($rows->take(self::LIMIT) as $order) {
            // Database collation alone must not decide ownership. Reconstruct each complete frozen effect graph.
            app(PurchaseAccess::class)->assertOrder($order, $ownerKey, $principal);
            $orders[] = array_intersect_key(app(ReadOrder::class)->present($order), array_flip(self::FIELDS));
        }

        foreach ($rows->take(self::LIMIT) as $order) {
            app(PurchaseAccess::class)->assertOrder($order, $ownerKey, $principal);
        }

        return ['orderHistorySchema' => 1, 'testOnly' => true, 'orders' => $orders, 'limit' => self::LIMIT,
            'nextCursor' => $rows->count() > self::LIMIT ? $rows[self::LIMIT - 1]->public_id : null];
    }
}
