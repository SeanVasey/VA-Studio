<?php

namespace App\Domain\Contracts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Recoverable claim coordination, separate from immutable requests and original-document evidence. */
final class ContractRenderWork extends Model
{
    public const REASONS = ['render_failed', 'storage_failed', 'evidence_changed', 'profile_changed',
        'unsupported_input', 'invalid_pdf', 'retry_exhausted', 'original_unavailable'];

    protected $table = 'contract_render_work';
    protected $guarded = ['id'];
    protected $hidden = ['claim_token'];
    protected $attributes = ['state' => 'pending', 'attempts' => 0];

    protected function casts(): array
    {
        return ['contract_render_request_id' => 'integer', 'attempts' => 'integer',
            'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function request(): BelongsTo { return $this->belongsTo(ContractRenderRequest::class, 'contract_render_request_id'); }

    protected static function booted(): void
    {
        static::updating(function (self $work): void {
            if ($work->isDirty(['id', 'contract_render_request_id', 'created_at']) || $work->getOriginal('state') === 'completed') {
                throw new LogicException('Contract work identity and completed outcome are immutable.');
            }
        });
        static::saving(function (self $work): void {
            $processing = $work->state === 'processing';
            $retry = $work->state === 'retry';
            $quarantined = $work->state === 'quarantined';
            if (! in_array($work->state, ['pending', 'processing', 'retry', 'completed', 'quarantined'], true)
                || $work->attempts < 0 || $work->attempts > 5
                || ($processing && (! is_string($work->claim_token)
                    || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $work->claim_token)
                    || $work->lease_expires_at === null || $work->attempts === 0))
                || (! $processing && ($work->claim_token !== null || $work->lease_expires_at !== null))
                || ($retry && ($work->attempts === 0 || $work->next_attempt_at === null || ! in_array($work->reason, ['render_failed', 'storage_failed'], true)))
                || (! $retry && $work->next_attempt_at !== null)
                || ($quarantined && ($work->attempts === 0 || ! in_array($work->reason, self::REASONS, true)))
                || (! $quarantined && ! $retry && $work->reason !== null)) {
                throw new LogicException('Invalid contract render work state.');
            }
        });
        static::deleting(fn () => throw new LogicException('Contract render work must be retained.'));
    }
}
