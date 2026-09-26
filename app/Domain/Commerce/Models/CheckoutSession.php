<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable hosted-session binding. Session existence does not confirm payment. */
final class CheckoutSession extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['account_id', 'provider_session_id', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['checkout_intent_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class, 'checkout_intent_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(CheckoutObservation::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Checkout session evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Checkout session evidence must be retained.'));
    }
}
