<?php

namespace App\Domain\Inquiries\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** ID-only operator inbox work; submitted is a handoff result, never proof of delivery. */
final class InquiryNotificationIntent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['claim_token'];

    protected function casts(): array
    {
        return ['customer_inquiry_id' => 'integer', 'operator_user_id' => 'integer', 'attempts' => 'integer',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $intent): void {
            $inquiry = CustomerInquiry::find($intent->customer_inquiry_id);
            if ($inquiry === null || (string) $inquiry->operator_user_id !== (string) $intent->operator_user_id
                || $intent->kind !== 'operator_inbox_v1' || $intent->state !== 'pending'
                || $intent->created_at === null || $intent->updated_at === null
                || ! $intent->created_at->equalTo($intent->updated_at)) {
                throw new LogicException('Inquiry notification creation requires the frozen inquiry operator and an empty pending intent.');
            }
            $intent->assertShape();
        });
        self::updating(function (self $intent): void {
            if (array_diff(array_keys($intent->getDirty()), ['state', 'attempts', 'claim_token', 'lease_expires_at', 'next_attempt_at', 'outcome', 'updated_at']) !== []) {
                throw new LogicException('Inquiry notification identity and original operator are immutable.');
            }
            $intent->assertShape();
            $intent->assertTransition();
        });
        self::deleting(fn () => throw new LogicException('Inquiry notification deletion requires a separately approved retention workflow.'));
    }

    private function assertShape(): void
    {
        $attempts = $this->getAttributes()['attempts'] ?? null;
        $clearClaim = $this->claim_token === null && $this->lease_expires_at === null;
        $clearSchedule = $this->next_attempt_at === null;
        $clearOutcome = $this->outcome === null;
        $valid = is_int($attempts) && $attempts >= 0 && $attempts <= 3
            && $this->created_at !== null && $this->updated_at !== null && $this->updated_at->greaterThanOrEqualTo($this->created_at)
            && match ($this->state) {
                'pending' => $attempts === 0 && $clearClaim && $clearSchedule && $clearOutcome,
                'processing' => $attempts >= 1 && is_string($this->claim_token) && strlen($this->claim_token) === 36
                    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $this->claim_token) === 1
                    && $this->lease_expires_at !== null && $this->lease_expires_at->greaterThan($this->updated_at) && $clearSchedule && $clearOutcome,
                'retry' => $attempts >= 1 && $attempts < 3 && $clearClaim && $this->next_attempt_at !== null
                    && $this->next_attempt_at->greaterThan($this->updated_at) && $this->outcome === 'definitely_not_submitted',
                'submitted' => $attempts >= 1 && $clearClaim && $clearSchedule && $this->outcome === 'handed_off',
                'unknown' => $attempts >= 1 && $clearClaim && $clearSchedule && in_array($this->outcome, ['handoff_uncertain', 'lease_expired'], true),
                'blocked' => $clearClaim && $clearSchedule && (in_array($this->outcome, ['authority_withdrawn', 'configuration_withdrawn'], true)
                    || ($attempts === 3 && $this->outcome === 'retry_exhausted')),
                default => false,
            };
        if (! $valid) {
            throw new LogicException('Inquiry notification state, claim or outcome is invalid.');
        }
    }

    private function assertTransition(): void
    {
        $oldState = $this->getRawOriginal('state');
        $oldAttempts = (int) $this->getRawOriginal('attempts');
        $oldUpdated = CarbonImmutable::parse($this->getRawOriginal('updated_at'));
        $oldLease = $this->getRawOriginal('lease_expires_at');
        $sameAttempts = $this->attempts === $oldAttempts;
        $claim = $this->state === 'processing' && $this->attempts === $oldAttempts + 1 && $oldAttempts < 3
            && ($oldState === 'pending' || ($oldState === 'retry'
                && CarbonImmutable::parse($this->getRawOriginal('next_attempt_at'))->lessThanOrEqualTo($this->updated_at)));
        $active = $oldState === 'processing' && $oldLease !== null
            && CarbonImmutable::parse($oldLease)->greaterThan($this->updated_at) && $sameAttempts;
        $finish = $active && (in_array($this->state, ['submitted', 'retry', 'blocked'], true)
            || ($this->state === 'unknown' && $this->outcome === 'handoff_uncertain'));
        $expired = $oldState === 'processing' && $oldLease !== null
            && CarbonImmutable::parse($oldLease)->lessThanOrEqualTo($this->updated_at)
            && $this->state === 'unknown' && $this->outcome === 'lease_expired' && $sameAttempts;
        $withdrawn = in_array($oldState, ['pending', 'retry'], true) && $this->state === 'blocked'
            && $this->outcome === 'authority_withdrawn' && $sameAttempts;
        if ($this->updated_at->lessThan($oldUpdated) || ! ($claim || $finish || $expired || $withdrawn)) {
            throw new LogicException('Inquiry notification transition is invalid.');
        }
    }
}
