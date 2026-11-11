<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable binding of a kit order to its one Stripe test Checkout Session. Written only by SoundKitCheckout. */
class SoundKitCheckoutSession extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_checkout_sessions';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_order_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }
}
