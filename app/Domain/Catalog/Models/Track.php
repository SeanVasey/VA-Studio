<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Rights\Models\RightsDeclaration;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Track extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['tags' => 'array', 'waveform' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Track $track) {
            if ($track->status === 'published') {
                $blockers = app(PublicationReadiness::class)->blockers($track);
                if ($blockers !== []) {
                    throw ValidationException::withMessages(['status' => implode(' ', $blockers)]);
                }
            }
        });
        static::deleting(function (Track $track) {
            if ($track->status === 'published') {
                throw ValidationException::withMessages(['track' => 'Unpublish the track before deleting it. Referenced evidence cannot be deleted.']);
            }
        });
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function rightsDeclarations(): HasMany
    {
        return $this->hasMany(RightsDeclaration::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }
}
