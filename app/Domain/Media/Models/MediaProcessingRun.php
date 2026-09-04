<?php

namespace App\Domain\Media\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class MediaProcessingRun extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['profile' => 'array', 'output_asset_ids' => 'array', 'evidence' => 'array', 'attempts' => 'integer', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'failed_at' => 'datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'source_asset_id');
    }

    public function outputs(): HasMany
    {
        return $this->hasMany(MediaAsset::class, 'processing_run_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            if ($run->getOriginal('status') === 'completed') {
                throw ValidationException::withMessages(['media' => 'Completed processing evidence is immutable.']);
            }
        });
        static::deleting(function (self $run) {
            if ($run->status === 'completed') {
                throw ValidationException::withMessages(['media' => 'Completed processing evidence cannot be deleted.']);
            }
        });
    }
}
