<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable entitlement to one exact kit archive revision under one license version. Written only by SoundKitPayments. */
class SoundKitGrant extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_grants';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_order_id' => 'integer', 'sound_kit_payment_id' => 'integer', 'sound_kit_product_id' => 'integer', 'sound_kit_revision_id' => 'integer', 'license_version_id' => 'integer', 'granted_at' => 'immutable_datetime'];
    }
}
