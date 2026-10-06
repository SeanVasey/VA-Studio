<?php

namespace App\Domain\Commerce\Policy\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionTrackPolicyVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['production_track_policy_draft_id' => 'integer', 'number' => 'integer', 'created_by' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Retain immutable production policy versions through the domain command.'));
        static::deleting(fn () => throw new LogicException('Retain immutable production policy versions.'));
    }
}
