<?php

namespace App\Domain\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class StemsRecording extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['evidence' => 'array', 'verified_at' => 'immutable_datetime'];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'master_asset_id');
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'preview_asset_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['recording' => 'Recording associations are immutable. Upload a new stems revision to correct an association.']));
        static::deleting(fn () => throw ValidationException::withMessages(['recording' => 'Recording evidence must be retained.']));
    }
}
