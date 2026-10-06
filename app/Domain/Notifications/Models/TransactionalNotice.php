<?php

namespace App\Domain\Notifications\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable private intent. A notice reference conveys no account, order or download authority. */
final class TransactionalNotice extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['event_key', 'capture_ciphertext', 'capture_hash', 'recipient_hmac', 'payload_hash', 'request_hmac'];

    protected function casts(): array
    {
        return ['account_id' => 'integer', 'user_id' => 'integer', 'access_version' => 'integer',
            'order_id' => 'integer', 'activation_id' => 'integer', 'claim_id' => 'integer',
            'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Notification intent is immutable.'));
        self::deleting(fn () => throw new LogicException('Notification evidence requires an approved retention workflow.'));
    }
}
