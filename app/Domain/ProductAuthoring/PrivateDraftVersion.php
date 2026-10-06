<?php

namespace App\Domain\ProductAuthoring;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class PrivateDraftVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['manifest'];

    protected function casts(): array
    {
        return ['draft_id' => 'integer', 'number' => 'integer', 'manifest' => 'encrypted:array',
            'created_by' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['title' => 'Retain immutable draft versions; save a new version.']));
        static::deleting(fn () => throw ValidationException::withMessages(['title' => 'Retain the complete draft history.']));
    }
}
