<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Operator control of a kit order's test delivery (absent = not enabled). Written only by SoundKitDelivery. */
class SoundKitDeliveryControl extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_delivery_controls';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_order_id' => 'integer', 'version' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
