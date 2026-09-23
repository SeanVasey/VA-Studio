<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ExclusiveActivation extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['snapshot', 'snapshot_hash'];

    protected function casts(): array
    {
        return ['offer_revision_id' => 'integer', 'rights_scope_id' => 'integer', 'activated_by' => 'integer',
            'snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Exclusive activation evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Exclusive activation evidence must be retained.'));
    }
}
