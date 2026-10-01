<?php

namespace App\Domain\Commerce\Orders;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\QuoteRequest;
use SensitiveParameter;

/** Current session ownership only. Listing retained evidence never claims identity, payment or delivery. */
final class ReadOwnedTestOrders
{
    public const LIMIT = 20;
    private const FIELDS = ['id', 'createdAt', 'testOnly', 'payable', 'currency', 'totalMinor',
        'status', 'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus'];

    public function handle(#[SensitiveParameter] string $ownerKey, ?string $before = null): array
    {
        QuoteRequest::owner($ownerKey);
        $query = Order::where('owner_key', $ownerKey);
        if ($before !== null) {
            if (! OrderRequest::uuid($before)) { throw new QuoteException('ORDER_HISTORY_CURSOR_INVALID', 422); }
            $anchor = Order::where('owner_key', $ownerKey)->where('public_id', $before)->first();
            if (! $anchor || ! hash_equals($anchor->owner_key, $ownerKey)) {
                throw new QuoteException('ORDER_HISTORY_CURSOR_INVALID', 422);
            }
            $query->where(fn ($page) => $page->where('created_at', '<', $anchor->created_at)
                ->orWhere(fn ($tie) => $tie->where('created_at', $anchor->created_at)->where('id', '<', $anchor->id)));
        }
        $rows = $query->orderByDesc('created_at')->orderByDesc('id')->limit(self::LIMIT + 1)->get();
        $orders = [];
        foreach ($rows->take(self::LIMIT) as $order) {
            // Database collation alone must not decide ownership. Reconstruct each complete frozen effect graph.
            if (! hash_equals($order->owner_key, $ownerKey)) { throw new QuoteException('ORDER_NOT_FOUND', 404); }
            $orders[] = array_intersect_key(app(ReadOrder::class)->present($order), array_flip(self::FIELDS));
        }

        return ['orderHistorySchema' => 1, 'testOnly' => true, 'orders' => $orders, 'limit' => self::LIMIT,
            'nextCursor' => $rows->count() > self::LIMIT ? $rows[self::LIMIT - 1]->public_id : null];
    }
}
