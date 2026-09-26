<?php

namespace App\Domain\Commerce\Payments;

use Illuminate\Support\Facades\DB;

final class PaymentProcessingPolicy
{
    public function account(): string
    {
        $account = config('payments.stripe.account_id');
        if (config('payments.stripe.processing_enabled') !== true || ! app()->environment('local', 'testing')
            || config('payments.stripe.mode') !== 'test' || ! is_string($account)
            || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)) {
            throw new PaymentVerificationException('unavailable');
        }

        return $account;
    }

    public function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new PaymentVerificationException('unavailable');
            }
        }
    }
}
