<?php

namespace App\Jobs;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class FinalizeTestPaymentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public readonly int $paymentId)
    {
        $this->onQueue('payments');
        $this->afterCommit();
    }

    public function handle(FinalizeTestPayment $finalizer): void
    {
        $finalizer->handle($this->paymentId);
    }
}
