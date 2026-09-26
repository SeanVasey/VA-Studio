<?php

namespace App\Domain\Commerce\Payments;

use App\Jobs\ProcessStripeReceiptJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DispatchStripeReceipt
{
    public function handle(int $receiptId): void
    {
        try {
            app(PaymentProcessingPolicy::class)->account();
            $connection = config('queue.default');
            $driver = is_string($connection) ? config('queue.connections.'.$connection.'.driver') : null;
            // An accidental synchronous queue must never run provider I/O inside the webhook request.
            if (! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) { return; }
            $send = static function () use ($receiptId): void {
                try { Bus::dispatch(new ProcessStripeReceiptJob($receiptId)); }
                catch (Throwable) { /* Receipt scanner recovers a missed dispatch. */ }
            };
            if (DB::transactionLevel() > 0) { DB::afterCommit($send); }
            else { $send(); }
        } catch (Throwable) { /* Durable receipt remains the acknowledgment boundary. */ }
    }
}
