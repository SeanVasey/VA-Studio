<?php

namespace App\Domain\Media\Models;

use App\Domain\Catalog\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class MediaAsset extends Model
{
    public const ROLES = ['artwork', 'preview_tagged', 'download_mp3', 'master_wav', 'stems_zip'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['technical_metadata' => 'array', 'verified_at' => 'datetime'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(MediaProcessingRun::class, 'source_asset_id');
    }

    public function processingRun(): BelongsTo
    {
        return $this->belongsTo(MediaProcessingRun::class, 'processing_run_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function isPublicDerivative(): bool
    {
        return in_array($this->role, ['artwork', 'preview_tagged'], true);
    }

    protected static function booted(): void
    {
        static::updating(function (MediaAsset $asset) {
            if (in_array($asset->getOriginal('status'), ['ready', 'processed'], true)) {
                throw ValidationException::withMessages(['asset' => 'Verified media revisions are immutable. Upload a new revision.']);
            }
        });
        static::deleting(function (MediaAsset $asset) {
            if (in_array($asset->status, ['ready', 'processed'], true)) {
                throw ValidationException::withMessages(['asset' => 'Verified media revisions cannot be deleted.']);
            }
        });
    }
}
