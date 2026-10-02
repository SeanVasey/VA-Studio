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
        return ['bpm' => 'integer', 'metadata_version' => 'integer', 'publication_version' => 'integer', 'tags' => 'array', 'waveform' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::saving(function (Track $track) {
            $reserved = $track->getRawOriginal('published_slug');
            if ($reserved !== null && ($track->slug !== $reserved || $track->published_slug !== $reserved)) {
                throw ValidationException::withMessages(['slug' => 'A published track URL cannot change, including after unpublishing.']);
            }
            if ($track->status === 'published' || $track->published_at !== null) {
                $track->published_slug ??= $track->slug;
            }
            if ($track->status === 'published') {
                $blockers = app(PublicationReadiness::class)->blockers($track);
                if ($blockers !== []) {
                    throw ValidationException::withMessages(['status' => implode(' ', $blockers)]);
                }
            }
        });
        static::deleting(function (Track $track) {
            if ($track->published_slug !== null || $track->status === 'published') {
                throw ValidationException::withMessages(['track' => 'Retain this track to preserve its published URL. Unpublish it to remove it from the catalog.']);
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
