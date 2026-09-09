<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use Illuminate\Console\Command;

final class StripeInboxStatus extends Command
{
    protected $signature = 'vasey:stripe-inbox {--limit=20 : Number of recent receipts, from 1 to 100}';

    protected $description = 'Inspect test webhook receipt metadata without exposing payloads or processing payments';

    public function handle(): int
    {
        $limit = (string) $this->option('limit');
        $account = config('payments.stripe.account_id');
        if (! preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/', $limit)
            || ! is_string($account) || ! preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/', $account)) {
            $this->error('Configure STRIPE_ACCOUNT_ID and use a limit from 1 to 100.');

            return self::FAILURE;
        }
        $receipts = StripeWebhookReceipt::query()->where('account_id', $account)->where('livemode', false);
        $this->line('Test receipts retained: '.$receipts->count());
        $this->line('Receipt storage only. Payment reconciliation and order finalization are not implemented yet.');
        $this->table(['Event', 'Type', 'Object', 'Received UTC'], $receipts->orderByDesc('id')->limit((int) $limit)
            ->get(['event_id', 'event_type', 'object_id', 'received_at'])
            ->map(fn (StripeWebhookReceipt $receipt) => [$receipt->event_id, $receipt->event_type, $receipt->object_id ?? '(none)', $receipt->received_at->utc()->toIso8601String()])->all());

        return self::SUCCESS;
    }
}
