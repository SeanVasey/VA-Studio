<?php

namespace App\Domain\SoundKits\Purchases\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable record of a kit license contract original. Written only by SoundKitContracts. */
class SoundKitContract extends Model
{
    public $timestamps = false;

    protected $table = 'sound_kit_contracts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_grant_id' => 'integer', 'size_bytes' => 'integer', 'pages' => 'integer', 'issued_at' => 'immutable_datetime'];
    }
}
