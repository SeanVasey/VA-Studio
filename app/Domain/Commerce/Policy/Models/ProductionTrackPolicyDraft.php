<?php

namespace App\Domain\Commerce\Policy\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionTrackPolicyDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'created_by' => 'integer', 'created_at' => 'datetime', 'updated_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the reviewed production policy draft command.'));
        static::deleting(fn () => throw new LogicException('Retain production policy draft history.'));
    }
}
