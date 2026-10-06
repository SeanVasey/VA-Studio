<?php

namespace App\Domain\Customers;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\QuoteException;
use App\Domain\Customers\Models\CustomerPurchaseClaim;
use App\Domain\Delivery\DeliveryException;
use SensitiveParameter;

/** Exact-order read/download access only. Never resolves quote, checkout or another order sharing the original owner. */
final class PurchaseAccess
{
    /** A claimed projection cannot escape an observed withdrawal during retained-graph reconstruction. */
    public function read(string $id, #[SensitiveParameter] string $owner, ?CustomerPrincipal $principal, callable $projection): mixed
    {
        $original = $this->owner($id, $owner, $principal);
        $result = $projection($original);
        if ($principal) {
            $this->owner($id, $owner, $principal);
        }

        return $result;
    }

    public function deliveryOwner(string $id, #[SensitiveParameter] string $owner, ?CustomerPrincipal $principal): string
    {
        try {
            return $this->owner($id, $owner, $principal);
        } catch (QuoteException) {
            throw new DeliveryException('not_found');
        }
    }

    public function owner(string $id, #[SensitiveParameter] string $owner, ?CustomerPrincipal $principal): string
    {
        if (! OrderRequest::uuid($id) || ! preg_match('/\A[a-f0-9]{64}\z/D', $owner)) {
            throw new QuoteException('ORDER_NOT_FOUND', 404);
        }
        $order = Order::where('public_id', $id)->first();
        if (! $order || ! hash_equals($order->public_id, $id)) {
            throw new QuoteException('ORDER_NOT_FOUND', 404);
        }
        $this->assertOrder($order, $owner, $principal);

        return $order->owner_key;
    }

    public function assertOrder(Order $order, #[SensitiveParameter] string $owner, ?CustomerPrincipal $principal): void
    {
        if ($principal) {
            app(CustomerAccess::class)->current($principal);
            if (hash_equals($principal->ownerKey, $order->owner_key) && hash_equals($owner, $principal->ownerKey)) {
                return;
            }
            if (! hash_equals($owner, $principal->ownerKey) && ! hash_equals($owner, $order->owner_key)) {
                throw new CustomerAccessException;
            }
            $claim = CustomerPurchaseClaim::where('order_id', $order->id)->where('account_id', $principal->accountId)->first();
            if (! $claim) {
                throw new QuoteException('ORDER_NOT_FOUND', 404);
            }
            app(CustomerPurchaseClaims::class)->verify($claim, $order, $principal);
        } elseif (! hash_equals($order->owner_key, $owner)) {
            throw new QuoteException('ORDER_NOT_FOUND', 404);
        }
    }
}
