<?php

namespace App\Domain\Commerce\Finalization;

use App\Jobs\FinalizeTestPaymentJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchTestFinalization
{
    public function handle(int $paymentId): void
    {
        try {
            app(FinalizationPolicy::class)->current();
            $connection = config('queue.default');
            $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
            if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) { return; }
            $send = static function () use ($paymentId): void {
                try { Bus::dispatch(new FinalizeTestPaymentJob($paymentId)); }
                catch (Throwable) { /* The verified-payment scanner recovers missed dispatch. */ }
            };
            if (DB::transactionLevel() > 0) { DB::afterCommit($send); }
            else { $send(); }
        } catch (Throwable) { /* Verification remains durable independently of fulfillment. */ }
    }
}
