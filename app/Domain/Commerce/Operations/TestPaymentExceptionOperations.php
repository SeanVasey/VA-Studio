<?php

namespace App\Domain\Commerce\Operations;

use App\Domain\Commerce\Finalization\ReadFinalization;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentExceptionWork;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\PaymentFinancialEvidence;
use App\Domain\Commerce\Payments\PaymentProcessingPolicy;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\Environment\TestEnvironment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/** Operational history only: this service cannot resolve money, release resources or grant rights. */
final class TestPaymentExceptionOperations
{
    public const LEASE_SECONDS = 120;

    /** Minimized operator review; the displayed sequence is the submit-time comparison, never recaptured on apply. */
    public function review(string $publicId, ?User $actor): array
    {
        $this->outsideTransactions();

        return $this->safe(fn (): array => DB::transaction(function () use ($publicId, $actor): array {
            [$record, $current, $work] = $this->locked($publicId, $actor);
            AuditEvent::recordAttributed('commerce.payment_exception.operations_reviewed', $record,
                ['finalization_id' => $record->public_id, 'sequence' => $work->sequence, 'test_only' => true], $current->id);

            return ['sequence' => $work->sequence, 'history' => $this->events($record)
                ->reorder('sequence', 'desc')->limit(20)->get()->map(fn ($event) => $this->project($event))->all()];
        }, 5));
    }

    public function disposition(string $publicId, ?User $actor, string $requestId, int $expectedSequence, string $disposition): array
    {
        $this->outsideTransactions();
        $this->request($requestId, $expectedSequence);
        if (! in_array($disposition, ['acknowledged', 'needs_review'], true)) {
            throw new RuntimeException('Invalid test exception disposition.');
        }

        return $this->safe(fn (): array => DB::transaction(function () use ($publicId, $actor, $requestId, $expectedSequence, $disposition): array {
            [$record, $current, $work] = $this->locked($publicId, $actor);
            $existing = $this->events($record)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->kind !== 'disposition' || $existing->outcome !== $disposition || $existing->actor_id !== $current->id) {
                    throw new RuntimeException('Test exception request changed.');
                }

                return $this->project($existing);
            }
            $this->expected($work, $expectedSequence);

            return $this->project($this->append($record, $current, $work, $requestId, 'disposition', $disposition));
        }, 5));
    }

    /** Manual own-account GET retrieval; provider I/O is never performed with a database transaction open. */
    public function reconcile(string $publicId, ?User $actor, string $requestId, int $expectedSequence): array
    {
        $this->outsideTransactions();
        $this->request($requestId, $expectedSequence);
        app(PaymentProcessingPolicy::class)->account();
        $claim = $this->safe(fn (): array => DB::transaction(function () use ($publicId, $actor, $requestId, $expectedSequence): array {
            [$record, $current, $work] = $this->locked($publicId, $actor);
            $events = $this->events($record)->where('request_id', $requestId)->get();
            if ($events->isNotEmpty()) {
                $requested = $events->firstWhere('kind', 'reconciliation_requested');
                if (! $requested || $requested->actor_id !== $current->id) {
                    throw new RuntimeException('Test exception request changed.');
                }
                if ($observed = $events->firstWhere('kind', 'reconciliation_observed')) {
                    return ['result' => $this->project($observed)];
                }
                // A reclaimed request may not jump over another operator's reviewed disposition.
                if ($work->sequence !== $requested->sequence || $work->request_id !== $requestId) {
                    throw new RuntimeException('Test exception request was superseded.');
                }
            } else {
                $this->expected($work, $expectedSequence);
            }
            if ($work->lease_expires_at?->greaterThan(now())) {
                return ['result' => ['testOnly' => true, 'status' => 'busy', 'sequence' => $work->sequence]];
            }
            if ($events->isEmpty()) {
                $this->append($record, $current, $work, $requestId, 'reconciliation_requested', 'pending');
            }
            $work->fill(['request_id' => $requestId, 'claim_token' => (string) Str::uuid(),
                'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS)])->save();

            return ['token' => $work->claim_token, 'intent_id' => $record->payment->checkout_intent_id,
                'payment_id' => $record->payment->provider_payment_intent_id];
        }, 5));
        if (isset($claim['result'])) {
            return $claim['result'];
        }

        $outcome = 'attention';
        $financial = null;
        try {
            $intent = CheckoutIntent::findOrFail($claim['intent_id']);
            $inspection = app(VerifyTestPayment::class)->inspect($intent->session()->sole()->provider_session_id, $intent);
            if ($inspection['intent_id'] === $claim['intent_id']
                && ($inspection['evidence']['payment']['id'] ?? null) === $claim['payment_id']) {
                $candidate = $inspection['evidence']['outcome'];
                $outcome = in_array($candidate, ['confirmed', 'pending', 'authorized', 'expired', 'canceled'], true) ? $candidate : 'attention';
                if ($outcome === 'confirmed') {
                    try {
                        $financial = app(PaymentFinancialEvidence::class)->capture(
                            app(StripeFinancialInspectionGateway::class)->financialState($claim['payment_id']),
                            ['account_id' => $inspection['evidence']['account_id'], 'payment' => $inspection['evidence']['payment'],
                                'amount_minor' => $inspection['evidence']['amount_minor']]);
                    } catch (PaymentVerificationException $error) {
                        $financial = ['state' => $error->reason === 'financial_incomplete' ? 'incomplete' : 'attention'];
                    } catch (Throwable) {
                        $financial = ['state' => 'unavailable'];
                    }
                }
            }
        } catch (PaymentVerificationException $error) {
            $outcome = in_array($error->reason, ['retry', 'unavailable'], true) ? 'unavailable' : 'attention';
        } catch (Throwable) {
            $outcome = 'unavailable';
        }
        $observedAt = now()->toImmutable()->utc()->startOfSecond();

        return $this->safe(fn (): array => DB::transaction(function () use ($publicId, $actor, $requestId, $claim, $outcome, $observedAt, $financial): array {
            [$record, $current, $work] = $this->locked($publicId, $actor);
            app(PaymentProcessingPolicy::class)->account();
            if ($work->request_id !== $requestId || $work->claim_token !== $claim['token']
                || $work->lease_expires_at === null || $work->lease_expires_at->lessThanOrEqualTo(now())) {
                return ['testOnly' => true, 'status' => 'stale', 'sequence' => $work->sequence];
            }
            if ($record->payment->checkout_intent_id !== $claim['intent_id'] || $record->payment->provider_payment_intent_id !== $claim['payment_id']) {
                throw new RuntimeException('Test exception evidence changed.');
            }
            $event = $this->append($record, $current, $work, $requestId, 'reconciliation_observed', $outcome, $observedAt);
            if ($financial !== null) {
                app(PaymentFinancialEvidence::class)->retain($event, $record, $financial);
            }
            $work->fill(['request_id' => null, 'claim_token' => null, 'lease_expires_at' => null])->save();

            return $this->project($event);
        }, 5));
    }

    /** Actor precedes order; immutable finalization identity is never rewritten or re-finalized. */
    private function locked(string $publicId, ?User $actor): array
    {
        $current = $actor?->exists ? User::whereKey($actor->getKey())->lockForUpdate()->first() : null;
        if (! $current || ! Gate::forUser($current)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($current)) {
            throw new AuthorizationException;
        }
        $account = config('payments.stripe.account_id');
        if (! TestEnvironment::admitsTestCommerce() || config('payments.stripe.mode') !== 'test'
            || ! OrderRequest::uuid($publicId) || ! is_string($account) || preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $account) !== 1) {
            throw (new ModelNotFoundException)->setModel(OrderFinalization::class);
        }
        $record = OrderFinalization::where('public_id', $publicId)->where('mode', 'test')->where('outcome', 'paid_exception')
            ->whereHas('payment', fn ($query) => $query->where('mode', 'test')->where('account_id', $account))->first();
        if (! $record || ! hash_equals($publicId, $record->public_id) || ! hash_equals($account, $record->payment->account_id)) {
            throw (new ModelNotFoundException)->setModel(OrderFinalization::class);
        }
        Order::whereKey($record->order_id)->lockForUpdate()->firstOrFail();
        // The order lock serializes coordination creation; work and event reads below are current locking reads.
        $original = app(ReadOrder::class)->verify($record->order);
        app(ReadFinalization::class)->verify($record, $original);
        $work = TestPaymentExceptionWork::where('order_finalization_id', $record->id)->lockForUpdate()->first();
        if (! $work) {
            $work = TestPaymentExceptionWork::create(['order_finalization_id' => $record->id, 'sequence' => 0]);
        }

        return [$record, $current, $work];
    }

    private function append(OrderFinalization $record, User $actor, TestPaymentExceptionWork $work, string $requestId, string $kind, string $outcome, mixed $observedAt = null): TestPaymentExceptionEvent
    {
        $event = TestPaymentExceptionEvent::create(['order_finalization_id' => $record->id, 'sequence' => $work->sequence + 1,
            'request_id' => $requestId, 'kind' => $kind, 'outcome' => $outcome, 'actor_id' => $actor->id,
            'observed_at' => $observedAt, 'created_at' => now()->utc()->startOfSecond()]);
        $work->sequence = $event->sequence;
        $work->save();
        AuditEvent::recordAttributed('commerce.payment_exception.'.$kind, $record,
            ['finalization_id' => $record->public_id, 'sequence' => $event->sequence, 'outcome' => $outcome, 'test_only' => true], $actor->id);

        return $event;
    }

    private function events(OrderFinalization $record): Builder
    {
        // Current reads prevent a waiter from replaying a pre-lock snapshot after another operator commits.
        return TestPaymentExceptionEvent::where('order_finalization_id', $record->id)->lockForUpdate()->orderBy('sequence');
    }

    private function expected(TestPaymentExceptionWork $work, int $expected): void
    {
        if ($work->sequence !== $expected || $work->lease_expires_at?->greaterThan(now())) {
            throw new RuntimeException('Test exception review changed.');
        }
    }

    private function project(TestPaymentExceptionEvent $event): array
    {
        $financial = app(PaymentFinancialEvidence::class)->project($event);

        return ['testOnly' => true, 'status' => $event->kind, 'outcome' => $event->outcome, 'sequence' => $event->sequence,
            'observedAt' => $event->observed_at?->toIso8601ZuluString(), 'fulfillment' => 'blocked',
            'refundDisputeState' => $financial['state'] ?? 'not_inspected'] + ($financial === null ? [] : ['financialObservation' => $financial]);
    }

    private function request(string $requestId, int $expected): void
    {
        if (! OrderRequest::uuid($requestId) || $expected < 0 || $expected >= 4294967294) {
            throw new RuntimeException('Invalid test exception request.');
        }
    }

    private function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new RuntimeException('Test exception operations require an independent transaction.');
            }
        }
    }

    private function safe(callable $operation): array
    {
        try {
            return $operation();
        } catch (AuthorizationException|ModelNotFoundException $error) {
            throw $error;
        } catch (Throwable) {
            throw new RuntimeException('Test exception operation is unavailable; refresh its retained history.');
        }
    }
}
