<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RightsScope extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $hidden = ['evidence_reference'];

    protected function casts(): array
    {
        return ['blocked' => 'boolean', 'control_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Use the audited inventory control command.'));
        static::deleting(fn () => throw new LogicException('Rights scope identity must be retained.'));
    }
}
