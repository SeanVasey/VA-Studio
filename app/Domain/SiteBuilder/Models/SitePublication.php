<?php

namespace App\Domain\SiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;

class SitePublication extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'active_release_id' => 'integer', 'updated_at' => 'immutable_datetime'];
    }
}
