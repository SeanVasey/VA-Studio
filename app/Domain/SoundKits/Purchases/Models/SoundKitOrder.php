<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable Stripe-test kit order (BL-13). Written only by SoundKitOrders. */
class SoundKitOrder extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_orders';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'checkout_request' => 'array', 'price_minor' => 'integer', 'sound_kit_product_id' => 'integer', 'created_at' => 'immutable_datetime', 'checkout_before' => 'immutable_datetime'];
    }
}
