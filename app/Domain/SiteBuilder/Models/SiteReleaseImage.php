<?php

namespace App\Domain\SiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Which ready site image a release shows in one slot, written with the release and never changed (D-25). */
class SiteReleaseImage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['site_release_id' => 'integer', 'site_image_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Site release image references are immutable.'));
        static::deleting(fn () => throw new LogicException('Site release image references must be retained.'));
    }
}
