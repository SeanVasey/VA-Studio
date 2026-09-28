<?php

namespace App\Domain\SiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SiteRelease extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['content' => 'array', 'schema_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Site releases are immutable. Save a new draft.'));
        static::deleting(fn () => throw new LogicException('Site releases must be retained.'));
    }
}
