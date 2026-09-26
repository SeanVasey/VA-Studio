<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only provider observation, separate from settled business effects. */
final class CheckoutObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['checkout_session_id' => 'integer', 'observed_at' => 'immutable_datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CheckoutSession::class, 'checkout_session_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Checkout observation evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Checkout observation evidence must be retained.'));
    }
}
