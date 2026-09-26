<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only authoritative provider observation, without fulfillment effects. */
final class PaymentObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['account_id', 'provider_payment_intent_id', 'evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['checkout_intent_id' => 'integer', 'stripe_webhook_receipt_id' => 'integer', 'observed_at' => 'immutable_datetime'];
    }

    public function intent(): BelongsTo
    {
        return $this->belongsTo(CheckoutIntent::class, 'checkout_intent_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(StripeWebhookReceipt::class, 'stripe_webhook_receipt_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payment observation evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Payment observation evidence must be retained.'));
    }
}
