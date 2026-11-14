<?php

namespace App\Domain\Commerce\RefundDispute;

use App\Domain\Commerce\Finalization\ReadFinalization;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\RefundDispute\Models\RefundDisputeWork;
use App\Domain\Grants\PaidTax\Models\PaidTaxOrderOrigin;
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
 *
 * The production tax chain follows the same order with its `paid_tax_order_origins` batch row in the Order row's place (then
 * its line rows, then the coordination row). Its delivery frame (`PaidTaxCommands`) reads that batch row under lock before its
 * hold check, so a hold either commits before the download decision or waits for it.
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
     * Inside the caller's transaction: the actor's row locked, then staff authority and MFA re-read from it. The first lock of every
     * staff writer, shared by the refused-order resolution (which has no batch or order row to lock after it).
     */
    public function lockStaff(?User $actor): User
    {
        RefundDisputeException::require(DB::transactionLevel() > 0, 'unavailable');
        $current = $actor?->exists ? User::whereKey($actor->getKey())->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }

        return $current;
    }

    /**
     * Inside the caller's transaction: lock the actor and order, verify the original evidence and return the
     * locked context. The policy is required for every write.
     *
     * @return array{0: User, 1: Order|PaidTaxOrderOrigin, 2: ?OrderFinalization, 3: array, 4: RefundDisputeWork, 5: array} the finalization is null for the production tax chain
     */
    public function lock(string $orderPublicId, ?User $actor): array
    {
        RefundDisputeException::require(DB::transactionLevel() > 0, 'unavailable');
        [$source, $batch] = $this->resolve($orderPublicId);
        if ($source === RefundDisputeSource::PRODUCTION_TAX) {
            return $this->lockProductionTax($batch, $actor);
        }
        // resolve() above already made plain, unlocked reads to pick the chain; environment, flag and policy refuse before the first lock is taken.
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
        $work = RefundDisputeWork::forOrder($locked)->lockForUpdate()->first();
        if ($work === null) {
            $at = now()->toImmutable()->utc()->startOfSecond();
            $work = RefundDisputeWork::create(['order_id' => $locked->id, 'order_source' => RefundDisputeSource::TEST, 'sequence' => 0, 'created_at' => $at, 'updated_at' => $at]);
        }

        return [$current, $locked, $finalization, $original, $work, $policy];
    }

    /**
     * Which chain an order id belongs to, by plain reads before anything is locked. A finalized production tax batch is found by its
     * public id; an id that also names a test order, or names neither, is not found (fail closed).
     *
     * @return array{0: string, 1: ?PaidTaxOrderOrigin}
     */
    public function resolve(string $orderPublicId): array
    {
        $batch = app(ProductionTaxOrders::class)->find($orderPublicId);
        if ($batch === null) {
            return [RefundDisputeSource::TEST, null];
        }
        RefundDisputeException::require(! Order::where('public_id', $orderPublicId)->exists(), 'not_found');

        return [RefundDisputeSource::PRODUCTION_TAX, $batch];
    }

    private function lockProductionTax(PaidTaxOrderOrigin $batch, ?User $actor): array
    {
        // Environment, flag and own account refuse before any row is locked; the retained policy values are checked below.
        $account = app(RefundDisputePolicy::class)->productionAccount();
        $current = $actor?->exists ? User::whereKey($actor->getKey())->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException;
        }
        RefundDisputeException::require($batch->funds_mode === 'test' && hash_equals($account, $batch->provider_account), 'not_found');
        $locked = PaidTaxOrderOrigin::whereKey($batch->id)->lockForUpdate()->first();
        RefundDisputeException::require($locked !== null && hash_equals($locked->public_id, $batch->public_id), 'not_found');
        $production = app(ProductionTaxOrders::class);
        $original = $production->verify($locked);
        $policy = $production->policy($original);
        $work = RefundDisputeWork::forOrder($locked)->lockForUpdate()->first();
        if ($work === null) {
            $at = now()->toImmutable()->utc()->startOfSecond();
            $work = RefundDisputeWork::create(['order_id' => $locked->id, 'order_source' => RefundDisputeSource::PRODUCTION_TAX, 'sequence' => 0,
                'created_at' => $at, 'updated_at' => $at]);
        }

        return [$current, $locked, null, $original, $work, $policy];
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
