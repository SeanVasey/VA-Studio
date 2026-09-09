<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable private evidence. A receipt is not a confirmed payment. */
final class StripeWebhookReceipt extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload_ciphertext', 'payload_sha256', 'event_fingerprint', 'signature_timestamp'];

    protected function casts(): array
    {
        return ['livemode' => 'boolean', 'provider_created_at' => 'integer', 'signature_timestamp' => 'integer', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stripe webhook evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Stripe webhook evidence must be retained.'));
    }
}
