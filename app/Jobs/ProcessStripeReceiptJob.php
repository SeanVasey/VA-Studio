<?php

namespace App\Jobs;

use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessStripeReceiptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 90;

    public function __construct(public readonly int $receiptId)
    {
        $this->onQueue('payments');
        $this->afterCommit();
    }

    public function handle(ProcessStripeReceipt $processor): void
    {
        $processor->handle($this->receiptId);
    }
}
