<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Delivery\ActivateTestFulfillment as Activate;
use App\Domain\Delivery\ActivationPolicy;
use Illuminate\Console\Command;
use Throwable;

final class ActivateTestFulfillment extends Command
{
    protected $signature = 'vasey:activate-test-fulfillment {order?} {--limit=25} {--after=}';
    protected $description = 'Record complete private test fulfillment proofs without authorizing downloads.';

    public function handle(): int
    {
        try {
            $policy = app(ActivationPolicy::class); $policy->current(); $account = $policy->account();
            ActivationPolicy::outsideTransactions();
            $id = $this->argument('order'); $cursor = $this->option('after'); $limit = $this->option('limit');
            if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100
                || ($id !== null && ! OrderRequest::uuid($id)) || ($cursor !== null && ! OrderRequest::uuid($cursor))
                || ($id !== null && $cursor !== null)) { throw new \InvalidArgumentException; }
            $query = Order::query()->select('orders.*')
                ->join('order_finalizations', 'order_finalizations.order_id', '=', 'orders.id')
                ->join('verified_payments', 'verified_payments.id', '=', 'order_finalizations.verified_payment_id')
                ->where('order_finalizations.outcome', 'paid')->where('order_finalizations.mode', 'test')
                ->where('verified_payments.mode', 'test')->where('verified_payments.account_id', $account);
            if ($id !== null) {
                $orders = (clone $query)->where('orders.public_id', $id)->get();
                if ($orders->isEmpty()) { throw new \InvalidArgumentException; }
            } else {
                $after = $cursor === null ? 0 : (clone $query)->where('orders.public_id', $cursor)->value('orders.id');
                if ($after === null) { throw new \InvalidArgumentException; }
                $orders = $query->where('orders.id', '>', $after)
                    ->whereNotExists(fn ($activations) => $activations->selectRaw('1')->from('test_fulfillment_activations')
                        ->whereColumn('test_fulfillment_activations.order_id', 'orders.id'))
                    ->orderBy('orders.id')->limit((int) $limit)->get();
            }
            foreach ($orders as $order) {
                $result = app(Activate::class)->handle((int) $order->id);
                $this->line($order->public_id.' '.$result);
            }
            if ($id === null && $orders->isNotEmpty()) { $this->line('NEXT_AFTER='.$orders->last()->public_id); }
            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Test fulfillment activation is unavailable or the request is invalid.');
            return self::FAILURE;
        }
    }
}
