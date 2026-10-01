<?php

namespace App\Domain\Inquiries\Notifications;

use App\Domain\Inquiries\Models\CustomerInquiry;
use App\Domain\Inquiries\Models\InquiryNotificationIntent;
use App\Jobs\NotifyInquiryOperatorJob;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/** Durable minimal intent; a wakeup is an optimization, never persistence evidence. */
final class InquiryNotificationWork
{
    public const LEASE_SECONDS = 120;

    public const MAX_ATTEMPTS = 3;

    public function retain(CustomerInquiry $inquiry): InquiryNotificationIntent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Inquiry notification intent requires the inquiry transaction.');
        }
        $at = now()->startOfSecond();
        $intent = InquiryNotificationIntent::create(['customer_inquiry_id' => $inquiry->id,
            'operator_user_id' => $inquiry->operator_user_id, 'kind' => 'operator_inbox_v1',
            'state' => 'pending', 'attempts' => 0, 'created_at' => $at, 'updated_at' => $at]);
        DB::afterCommit(function () use ($intent): void {
            if (! $this->enabled()) {
                return;
            }
            try {
                NotifyInquiryOperatorJob::dispatch((int) $intent->id);
            } catch (Throwable) {
                // Retained scanning recovers missed dispatch. Never log submitted input or queue errors.
            }
        });

        return $intent;
    }

    public function enabled(): bool
    {
        return config('inquiries.operator_notifications_enabled') === true
            && app()->bound(InquiryAlertTransport::class);
    }

    /** Claim only when a trusted adapter is explicitly bound. Never reclaim uncertain handoffs. */
    public function claim(int $id): ?InquiryNotificationIntent
    {
        if (! $this->enabled()) {
            return null;
        }

        return DB::transaction(function () use ($id): ?InquiryNotificationIntent {
            $intent = InquiryNotificationIntent::lockForUpdate()->findOrFail($id);
            if ($intent->state === 'processing') {
                if ($intent->lease_expires_at->lessThanOrEqualTo(now())) {
                    $this->finish($intent, 'unknown', 'lease_expired');
                }

                return null;
            }
            if (! in_array($intent->state, ['pending', 'retry'], true)
                || ($intent->next_attempt_at !== null && $intent->next_attempt_at->greaterThan(now()))) {
                return null;
            }
            if (! $this->enabled()) {
                return null;
            }
            if ($this->operator($intent) === null) {
                $this->finish($intent, 'blocked', 'authority_withdrawn');

                return null;
            }
            $intent->fill(['state' => 'processing', 'attempts' => $intent->attempts + 1,
                'claim_token' => (string) Str::uuid(), 'lease_expires_at' => now()->addSeconds(self::LEASE_SECONDS),
                'next_attempt_at' => null, 'outcome' => null, 'updated_at' => now()])->save();

            return $intent;
        });
    }

    public function process(int $id): string
    {
        $this->outsideTransactions();
        if (! $this->enabled()) {
            return 'disabled';
        }
        $claim = $this->claim($id);
        if ($claim === null) {
            return InquiryNotificationIntent::findOrFail($id)->state;
        }

        return $this->handoff($claim);
    }

    /** Only process() owns this one-use in-memory claim; it is never a caller-facing replay entry point. */
    private function handoff(InquiryNotificationIntent $claim): string
    {
        $this->outsideTransactions();
        $ready = DB::transaction(function () use ($claim): ?array {
            $current = $this->owns($claim);
            if ($current === null) {
                return null;
            }
            if (! $this->enabled()) {
                $this->finish($current, 'blocked', 'configuration_withdrawn');

                return null;
            }
            if ($this->operator($current) === null) {
                $this->finish($current, 'blocked', 'authority_withdrawn');

                return null;
            }
            $inquiry = CustomerInquiry::findOrFail($current->customer_inquiry_id);

            return ['intent' => $current, 'alert' => new OperatorInquiryAlert((int) $current->operator_user_id, $inquiry->public_id)];
        });
        if ($ready === null) {
            return 'stale';
        }
        // Refresh authority/config after releasing locks and immediately before processor I/O.
        if (! $this->enabled()) {
            return $this->settle($claim, 'blocked', 'configuration_withdrawn');
        }
        if ($this->operator($ready['intent']) === null) {
            return $this->settle($claim, 'blocked', 'authority_withdrawn');
        }
        if ($ready['intent']->lease_expires_at->lessThanOrEqualTo(now())) {
            return $this->settle($claim, 'unknown', 'lease_expired');
        }
        try {
            app(InquiryAlertTransport::class)->submit($ready['alert']);
        } catch (InquiryAlertNotSubmitted) {
            return $this->settle($claim, $ready['intent']->attempts < self::MAX_ATTEMPTS ? 'retry' : 'blocked',
                $ready['intent']->attempts < self::MAX_ATTEMPTS ? 'definitely_not_submitted' : 'retry_exhausted');
        } catch (Throwable) {
            return $this->settle($claim, 'unknown', 'handoff_uncertain');
        }

        return $this->settle($claim, 'submitted', 'handed_off');
    }

    public function eligible(int $limit): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new LogicException('Inquiry notification scan limit is invalid.');
        }

        return InquiryNotificationIntent::where(function (Builder $query): void {
            $query->where('state', 'pending')
                ->orWhere(fn (Builder $retry) => $retry->where('state', 'retry')->where('next_attempt_at', '<=', now()))
                ->orWhere(fn (Builder $expired) => $expired->where('state', 'processing')->where('lease_expires_at', '<=', now()));
        })->orderBy('id')->limit($limit)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function operator(InquiryNotificationIntent $intent): ?User
    {
        $inquiry = CustomerInquiry::find($intent->customer_inquiry_id);
        $operator = User::find($intent->operator_user_id);

        return $inquiry !== null && $inquiry->operator_user_id === $intent->operator_user_id
            && $operator !== null && Gate::forUser($operator)->allows('administer-catalog') && AdminMultiFactor::satisfiedBy($operator)
            ? $operator : null;
    }

    private function outsideTransactions(): void
    {
        foreach (DB::getConnections() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new LogicException('Notification handoff must be outside application transactions.');
            }
        }
    }

    private function owns(InquiryNotificationIntent $claim): ?InquiryNotificationIntent
    {
        $current = InquiryNotificationIntent::lockForUpdate()->find($claim->id);
        if ($current === null || $current->state !== 'processing' || $current->claim_token !== $claim->claim_token) {
            return null;
        }
        if ($current->lease_expires_at->lessThanOrEqualTo(now())) {
            $this->finish($current, 'unknown', 'lease_expired');

            return null;
        }

        return $current;
    }

    private function settle(InquiryNotificationIntent $claim, string $state, string $outcome): string
    {
        return DB::transaction(function () use ($claim, $state, $outcome): string {
            $current = $this->owns($claim);
            if ($current === null) {
                return 'stale';
            }
            $this->finish($current, $state, $outcome);

            return $state;
        });
    }

    private function finish(InquiryNotificationIntent $intent, string $state, string $outcome): void
    {
        $intent->fill(['state' => $state, 'outcome' => $outcome, 'claim_token' => null, 'lease_expires_at' => null,
            'next_attempt_at' => $state === 'retry' ? now()->addSeconds(30 * $intent->attempts) : null, 'updated_at' => now()])->save();
        AuditEvent::record('inquiry.notification.'.$state, $intent, ['outcome' => $outcome, 'attempts' => $intent->attempts]);
    }
}
