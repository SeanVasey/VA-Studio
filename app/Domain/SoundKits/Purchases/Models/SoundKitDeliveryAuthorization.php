<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Short-lived single-use download authorization of a kit deliverable. Written only by SoundKitDelivery. */
class SoundKitDeliveryAuthorization extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_delivery_authorizations';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_order_id' => 'integer', 'sound_kit_grant_id' => 'integer', 'created_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime', 'redeemed_at' => 'immutable_datetime'];
    }
}
