<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Recoverable processing coordination, separate from immutable webhook evidence. */
final class StripeReceiptWork extends Model
{
    protected $table = 'stripe_receipt_work';

    protected $guarded = ['id'];

    protected $hidden = ['claim_token'];

    protected $attributes = ['state' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['stripe_webhook_receipt_id' => 'integer', 'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StripeWebhookReceipt::class, 'stripe_webhook_receipt_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $work): void {
            if ($work->isDirty(['id', 'stripe_webhook_receipt_id', 'created_at'])) {
                throw new LogicException('Stripe receipt work identity is immutable.');
            }
        });
        static::saving(function (self $work): void {
            $processing = $work->state === 'processing';
            if (! in_array($work->state, ['pending', 'processing', 'retry', 'processed', 'quarantined', 'unsupported'], true)
                || ($processing && (! is_string($work->claim_token) || strlen($work->claim_token) !== 36 || $work->lease_expires_at === null))
                || (! $processing && ($work->claim_token !== null || $work->lease_expires_at !== null))
                || $work->attempts < 0 || $work->attempts > 4294967295
                || ($work->outcome !== null && strlen($work->outcome) > 64)) {
                throw new LogicException('Invalid Stripe receipt work state.');
            }
        });
        static::deleting(fn () => throw new LogicException('Stripe receipt work must be retained.'));
    }
}
