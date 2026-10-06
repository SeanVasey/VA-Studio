<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\TransactionalNotificationPolicy as Policy;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Each claim is retained; a retry creates another attempt instead of overwriting a lease. */
final class TransactionalNoticeAttempt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'receipt_hash'];

    protected function casts(): array
    {
        return ['notice_id' => 'integer', 'number' => 'integer', 'started_at' => 'immutable_datetime',
            'lease_expires_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::saving(function (self $attempt): void {
            if (! in_array($attempt->state, ['leased', 'accepted', 'failed', 'uncertain'], true)
                || $attempt->number < 1 || $attempt->number > Policy::MAX_ATTEMPTS
                || ! $attempt->lease_expires_at->equalTo($attempt->started_at->addSeconds(Policy::LEASE_SECONDS))
                || ($attempt->state === 'leased' && ($attempt->reason !== null || $attempt->receipt_hash !== null || $attempt->finished_at !== null))
                || ($attempt->state !== 'leased' && ($attempt->finished_at === null || $attempt->finished_at->lessThan($attempt->started_at)))
                || ($attempt->state === 'accepted' && ($attempt->receipt_hash === null || ! in_array($attempt->reason, [null, 'capture_reconciled'], true)))
                || ($attempt->state === 'failed' && ($attempt->receipt_hash !== null || $attempt->reason !== 'private_storage_refused'))
                || ($attempt->state === 'uncertain' && ($attempt->receipt_hash !== null || ! in_array($attempt->reason, ['capture_unknown', 'lease_expired'], true)))) {
                throw new LogicException('Invalid private notification attempt.');
            }
        });
        self::updating(function (self $attempt): void {
            if (array_diff(array_keys($attempt->getDirty()), ['state', 'reason', 'receipt_hash', 'finished_at']) !== []
                || ! in_array($attempt->getOriginal('state'), ['leased', 'uncertain'], true)
                || ($attempt->getOriginal('state') === 'uncertain' && ($attempt->state !== 'accepted' || $attempt->reason !== 'capture_reconciled'))) {
                throw new LogicException('Notification claim and terminal outcomes are retained.');
            }
        });
        self::deleting(fn () => throw new LogicException('Notification attempts must be retained.'));
    }
}
