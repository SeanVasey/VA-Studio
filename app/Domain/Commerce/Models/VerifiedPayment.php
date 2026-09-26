<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Immutable confirmation of test payment; this record alone grants no purchase rights. */
final class VerifiedPayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['account_id', 'provider_payment_intent_id', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'order_attempt_id' => 'integer', 'checkout_intent_id' => 'integer',
            'checkout_session_id' => 'integer', 'amount_minor' => 'integer', 'confirmed_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(OrderAttempt::class, 'order_attempt_id');
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class, 'checkout_intent_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Verified payment evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Verified payment evidence must be retained.'));
    }
}
