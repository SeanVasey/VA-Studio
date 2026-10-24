<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Finalization\ReadFinalization;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeWork;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Fresh staff authority and the shared lock order for every A5 writer: actor User row, then the Order row,
 * then the order's coordination row, then current reads of its refund/dispute evidence. Delivery takes the
 * same Order row first, which serializes holds against download authorization and redemption.
 */
final class RefundDisputeAuthority
{
    /** Staff authority for reads: the catalog gate and the admin panel MFA rule, re-read from the database. */
    public function staff(?User $actor): User
    {
        $current = $actor?->exists ? User::find($actor->getKey()) : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current)) {
            throw new AuthorizationException;
        }

        return $current;
    }

    /**
     * Inside the caller's transaction: lock the actor and order, verify the original evidence and return the
     * locked context. The policy is required for every write.
     *
     * @return array{0: User, 1: Order, 2: OrderFinalization, 3: array, 4: RefundDisputeWork, 5: array}
     */
    public function lock(string $orderPublicId, ?User $actor): array
    {
        RefundDisputeException::require(DB::transactionLevel() > 0, 'unavailable');
        // Environment, flag and policy refuse before any row is locked or read.
        $policy = app(RefundDisputePolicy::class)->current();
        $current = $actor?->exists ? User::whereKey($actor->getKey())->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        [$order, $finalization] = $this->paidOrder($orderPublicId, app(RefundDisputePolicy::class)->account());
        $locked = Order::whereKey($order->id)->lockForUpdate()->first();
        RefundDisputeException::require($locked !== null && hash_equals($locked->public_id, $orderPublicId), 'not_found');
        try {
            $original = app(ReadOrder::class)->verify($locked);
            app(ReadFinalization::class)->verify($finalization, $original);
        } catch (Throwable) {
            throw new RefundDisputeException('unavailable');
        }
        $work = RefundDisputeWork::where('order_id', $locked->id)->lockForUpdate()->first();
        if ($work === null) {
            $at = now()->toImmutable()->utc()->startOfSecond();
            $work = RefundDisputeWork::create(['order_id' => $locked->id, 'sequence' => 0, 'created_at' => $at, 'updated_at' => $at]);
        }

        return [$current, $locked, $finalization, $original, $work, $policy];
    }

    /** A granted (`paid`) test finalization for the configured own account; anything else is not found. */
    public function paidOrder(string $orderPublicId, string $account): array
    {
        RefundDisputeException::require(OrderRequest::uuid($orderPublicId), 'not_found');
        $order = Order::where('public_id', $orderPublicId)->first();
        $finalization = $order === null ? null : OrderFinalization::where('order_id', $order->id)->where('mode', 'test')
            ->where('outcome', 'paid')->first();
        $payment = $finalization?->payment;
        RefundDisputeException::require($order !== null && $finalization !== null && $payment !== null && hash_equals($order->public_id, $orderPublicId)
            && $payment->mode === 'test' && hash_equals($account, $payment->account_id) && $payment->order_id === $order->id, 'not_found');

        return [$order, $finalization];
    }

    public static function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            RefundDisputeException::require($connection->transactionLevel() === 0, 'unavailable');
        }
    }

    public static function request(string $requestId, int $expectedSequence): void
    {
        RefundDisputeException::require(OrderRequest::uuid($requestId) && $expectedSequence >= 0 && $expectedSequence < 4294967294, 'invalid');
    }
}
