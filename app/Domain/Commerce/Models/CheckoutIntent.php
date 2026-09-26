<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

/** Immutable private provider request retained before any hosted session call. */
final class CheckoutIntent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['account_id', 'idempotency_key', 'request_ciphertext', 'request_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_attempt_id' => 'integer', 'created_at' => 'immutable_datetime',
            'initiate_before' => 'immutable_datetime', 'retry_before' => 'immutable_datetime', 'provider_expires_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(OrderAttempt::class, 'order_attempt_id');
    }

    public function session(): HasOne
    {
        return $this->hasOne(CheckoutSession::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Checkout intent evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Checkout intent evidence must be retained.'));
    }
}
