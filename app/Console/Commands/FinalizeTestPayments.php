<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use Illuminate\Console\Command;
use Throwable;

final class FinalizeTestPayments extends Command
{
    protected $signature = 'vasey:finalize-test-payments {order?} {--limit=25} {--after=}';
    protected $description = 'Finalize verified local test payments using retained order evidence.';

    public function handle(): int
    {
        try {
            app(FinalizationPolicy::class)->current();
            $account = app(FinalizationPolicy::class)->account();
            $id = $this->argument('order'); $cursor = $this->option('after'); $limit = $this->option('limit');
            if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100
                || ($id !== null && ! OrderRequest::uuid($id)) || ($cursor !== null && ! OrderRequest::uuid($cursor))
                || ($id !== null && $cursor !== null)) { throw new \InvalidArgumentException; }
            $query = Order::query()->select('orders.*')->join('verified_payments', 'verified_payments.order_id', '=', 'orders.id')
                ->where('verified_payments.account_id', $account)->where('verified_payments.mode', 'test');
            if ($id !== null) {
                $orders = (clone $query)->where('orders.public_id', $id)->get();
                if ($orders->isEmpty()) { throw new \InvalidArgumentException; }
            } else {
                $after = $cursor === null ? 0 : (clone $query)->where('orders.public_id', $cursor)->value('orders.id');
                if ($after === null) { throw new \InvalidArgumentException; }
                $orders = $query->where('orders.id', '>', $after)->whereNotExists(fn ($outcomes) => $outcomes->selectRaw('1')
                    ->from('order_finalizations')->whereColumn('order_finalizations.order_id', 'orders.id'))
                    ->orderBy('orders.id')->limit((int) $limit)->get();
            }
            foreach ($orders as $order) {
                $paymentId = VerifiedPayment::where('order_id', $order->id)->where('account_id', $account)->where('mode', 'test')->value('id');
                $result = $paymentId === null ? 'unverified' : app(FinalizeTestPayment::class)->handle((int) $paymentId);
                $this->line($order->public_id.' '.$result);
            }
            if ($id === null && $orders->isNotEmpty()) { $this->line('NEXT_AFTER='.$orders->last()->public_id); }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Test finalization is unavailable or the request is invalid.');

            return self::FAILURE;
        }
    }
}
