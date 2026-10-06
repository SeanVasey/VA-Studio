<?php

namespace App\Domain\Media\Models;

use Illuminate\Database\Eloquent\Model;

final class MediaUploadSession extends Model
{
    protected $guarded = [];

    protected $hidden = ['parts'];

    protected function casts(): array
    {
        return ['id' => 'integer', 'actor_id' => 'integer', 'track_id' => 'integer', 'size_bytes' => 'integer',
            'received_bytes' => 'integer', 'asset_id' => 'integer', 'parts' => 'array', 'expires_at' => 'immutable_datetime', 'cleaned_at' => 'immutable_datetime'];
    }
}
