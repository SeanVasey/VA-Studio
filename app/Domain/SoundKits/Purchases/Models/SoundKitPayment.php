<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable verified Stripe test payment of a kit order. Written only by SoundKitPayments. */
class SoundKitPayment extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_payments';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_order_id' => 'integer', 'stripe_webhook_receipt_id' => 'integer', 'amount_minor' => 'integer', 'evidence' => 'array', 'verified_at' => 'immutable_datetime'];
    }
}
