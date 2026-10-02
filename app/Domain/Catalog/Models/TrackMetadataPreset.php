<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

class TrackMetadataPreset extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'version' => 'integer', 'archived_at' => 'datetime'];
    }
}
