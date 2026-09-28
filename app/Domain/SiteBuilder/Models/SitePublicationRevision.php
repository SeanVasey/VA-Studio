<?php

namespace App\Domain\SiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SitePublicationRevision extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'release_id' => 'integer', 'previous_release_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Site publication history is immutable.'));
        static::deleting(fn () => throw new LogicException('Site publication history must be retained.'));
    }
}
