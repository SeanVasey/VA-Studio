<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RightsScopeOffer extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['evidence_reference'];

    protected function casts(): array
    {
        return ['rights_scope_id' => 'integer', 'offer_revision_id' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Scope linkage is immutable.'));
        static::deleting(fn () => throw new LogicException('Scope linkage must be retained.'));
    }
}
