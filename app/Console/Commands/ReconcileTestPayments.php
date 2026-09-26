<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutSession;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileTestPayments extends Command
{
    protected $signature = 'vasey:reconcile-test-payments {intent?} {--limit=25} {--after=}';
    protected $description = 'Verify known hosted test sessions, including payments whose webhook was not received';

    public function handle(): int
    {
        try {
            $account = app(PaymentProcessingPolicy::class)->account();
            $id = $this->argument('intent'); $limit = $this->option('limit'); $after = $this->option('after');
            if (($id !== null && ! OrderRequest::uuid($id)) || ! is_string($limit)
                || ! preg_match('/\A[1-9][0-9]{0,2}\z/', $limit) || (int) $limit > 100
                || ($after !== null && ! OrderRequest::uuid($after)) || ($id !== null && $after !== null)) {
                $this->error('INVALID_PAYMENT_COMMAND'); return self::FAILURE;
            }
            $cursor = $after === null ? 0 : CheckoutIntent::where('account_id', $account)->where('mode', 'test')->where('public_id', $after)->value('id');
            if ($cursor === null) { $this->error('INVALID_PAYMENT_COMMAND'); return self::FAILURE; }
            $query = CheckoutIntent::where('account_id', $account)->where('mode', 'test');
            if ($id !== null) { $query->where('public_id', $id); }
            else {
                $query->whereIn('id', CheckoutSession::select('checkout_intent_id'))
                    ->whereNotIn('id', VerifiedPayment::select('checkout_intent_id'))
                    ->where('id', '>', $cursor);
            }
            $intents = $query->orderBy('id')->limit((int) $limit)->get();
            if ($id !== null && $intents->isEmpty()) { $this->error('PAYMENT_INTENT_NOT_FOUND'); return self::FAILURE; }
            foreach ($intents as $intent) { $this->line($intent->public_id.' '.app(VerifyTestPayment::class)->reconcile($intent)); }
            // Advance past failures as well as successes; omit the cursor for a later sweep.
            if ($id === null && $intents->isNotEmpty()) { $this->line('NEXT_AFTER='.$intents->last()->public_id); }

            return self::SUCCESS;
        } catch (Throwable) { $this->error('PAYMENT_PROCESSING_UNAVAILABLE'); return self::FAILURE; }
    }
}
