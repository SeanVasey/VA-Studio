<?php

namespace App\Domain\Commerce\Payments;

use App\Domain\Commerce\Models\StripeReceiptWork;
use App\Domain\Commerce\Models\StripeWebhookReceipt;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PaymentWork
{
    public const LEASE_SECONDS = 120;

    public const MAX_ATTEMPTS = 8;

    public function claim(int $receiptId, bool $replay = false): ?StripeReceiptWork
    {
        $account = app(PaymentProcessingPolicy::class)->account();
        app(PaymentProcessingPolicy::class)->outsideTransactions();
        $receipt = StripeWebhookReceipt::whereKey($receiptId)->where('account_id', $account)->where('livemode', false)->first();
        if (! $receipt) {
            throw new PaymentVerificationException('unmatched');
        }
        try {
            StripeReceiptWork::firstOrCreate(['stripe_webhook_receipt_id' => $receiptId],
                ['state' => 'pending', 'attempts' => 0]);
        } catch (UniqueConstraintViolationException) {
            // A competing delivery created the same durable work row.
        }

        return DB::transaction(function () use ($receiptId, $replay): ?StripeReceiptWork {
            $work = StripeReceiptWork::where('stripe_webhook_receipt_id', $receiptId)->lockForUpdate()->firstOrFail();
            if ($work->state === 'processing' && $work->lease_expires_at->greaterThan(now())) {
                return null;
            }
            if (! $replay && (in_array($work->state, ['processed', 'quarantined', 'unsupported'], true)
                || ($work->next_attempt_at !== null && $work->next_attempt_at->greaterThan(now())))) {
                return null;
            }
            if ($replay) {
                $work->attempts = 0;
                AuditEvent::recordAttributed('commerce.payment.receipt_replayed', $work, ['receipt_id' => $receiptId, 'test_only' => true], null);
            } elseif ($work->attempts >= self::MAX_ATTEMPTS) {
                // Scheduler reclamation under the work lock, not an expired worker's finish.
                $work->fill(['state' => 'quarantined', 'outcome' => 'retry_exhausted', 'claim_token' => null,
                    'lease_expires_at' => null, 'next_attempt_at' => null])->save();

                return null;
            }
            $work->fill(['state' => 'processing', 'outcome' => null, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS), 'attempts' => $work->attempts + 1,
                'next_attempt_at' => null])->save();

            return $work;
        }, 5);
    }

    /** Lock after Order/CheckoutIntent, before ALL side effects. An expired worker owns nothing. */
    public function owns(StripeReceiptWork $claim): ?StripeReceiptWork
    {
        if (DB::transactionLevel() === 0) {
            throw new PaymentVerificationException('unavailable');
        }
        $current = StripeReceiptWork::whereKey($claim->id)->lockForUpdate()->first();
        if (! $current || $current->state !== 'processing' || $current->claim_token !== $claim->claim_token
            || $current->lease_expires_at === null || $current->lease_expires_at->lessThanOrEqualTo(now())) {
            return null;
        }

        return $current;
    }

    /** Caller owns this locked work row in its transaction. */
    public function finish(StripeReceiptWork $work, string $state, string $outcome): void
    {
        if (DB::transactionLevel() === 0) {
            throw new PaymentVerificationException('unavailable');
        }
        if ($work->state === 'processing' && $work->lease_expires_at->lessThanOrEqualTo(now())) {
            throw new PaymentVerificationException('stale');
        }
        if ($state === 'retry' && $work->attempts >= self::MAX_ATTEMPTS) {
            $state = 'quarantined';
            $outcome = 'retry_exhausted';
        }
        $work->fill(['state' => $state, 'outcome' => $outcome, 'claim_token' => null, 'lease_expires_at' => null,
            'next_attempt_at' => $state === 'retry' ? now()->addSeconds(min(3600, 30 * (2 ** max(0, $work->attempts - 1)))) : null])->save();
    }

    public function outcome(StripeReceiptWork $claim, string $state, string $outcome): string
    {
        return DB::transaction(function () use ($claim, $state, $outcome): string {
            $current = $this->owns($claim);
            if (! $current) {
                return 'stale';
            }
            $this->finish($current, $state, $outcome);

            return $current->outcome;
        }, 5);
    }

    /** Missing work, due retries and abandoned claims; filter before limit to prevent starvation. */
    public function eligible(int $limit): array
    {
        $account = app(PaymentProcessingPolicy::class)->account();

        return StripeWebhookReceipt::query()->where('account_id', $account)->where('livemode', false)
            ->where(function (Builder $query): void {
                $query->whereNotIn('id', StripeReceiptWork::select('stripe_webhook_receipt_id'))
                    ->orWhereIn('id', StripeReceiptWork::select('stripe_webhook_receipt_id')->where(function (Builder $work): void {
                        $work->where('state', 'pending')
                            ->orWhere(fn (Builder $retry) => $retry->where('state', 'retry')->where('next_attempt_at', '<=', now()))
                            ->orWhere(fn (Builder $lease) => $lease->where('state', 'processing')->where('lease_expires_at', '<=', now()));
                    }));
            })->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
