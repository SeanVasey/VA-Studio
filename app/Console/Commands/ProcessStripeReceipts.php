<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\PaymentWork;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use Illuminate\Console\Command;
use Throwable;

final class ProcessStripeReceipts extends Command
{
    protected $signature = 'vasey:process-stripe-receipts {receipt?} {--limit=25} {--replay}';
    protected $description = 'Process retained test receipts, recover abandoned claims, or explicitly replay one receipt';

    public function handle(): int
    {
        try {
            app(PaymentProcessingPolicy::class)->account();
            $id = $this->argument('receipt'); $limit = $this->option('limit'); $replay = (bool) $this->option('replay');
            if (! is_string($limit) || ! preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) || (int) $limit > 100
                || ($id !== null && (! is_string($id) || ! preg_match('/\A[1-9][0-9]{0,17}\z/', $id)))
                || ($replay && $id === null)) { $this->error('INVALID_PAYMENT_COMMAND'); return self::FAILURE; }
            $ids = $id === null ? app(PaymentWork::class)->eligible((int) $limit) : [(int) $id];
            foreach ($ids as $receiptId) {
                $this->line($receiptId.' '.app(ProcessStripeReceipt::class)->handle($receiptId, $replay));
            }

            return self::SUCCESS;
        } catch (Throwable) { $this->error('PAYMENT_PROCESSING_UNAVAILABLE'); return self::FAILURE; }
    }
}
