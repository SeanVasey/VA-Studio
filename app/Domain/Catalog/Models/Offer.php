<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Rights\Models\LicenseVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Offer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deliverable_asset_ids' => 'array', 'is_active' => 'boolean', 'price_minor' => 'integer'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function licenseVersion(): BelongsTo
    {
        return $this->belongsTo(LicenseVersion::class);
    }

    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(OfferRevision::class, 'current_revision_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OfferRevision::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Offer $offer) {
            $price = $offer->getAttributes()['price_minor'] ?? null;
            if ((! is_int($price) && (! is_string($price) || ! preg_match('/\A[0-9]+\z/D', $price))) || $price < 0 || $price > 2147483647 || ! preg_match('/\A[A-Z]{3}\z/D', $offer->currency ?? '')) {
                throw ValidationException::withMessages(['price_minor' => 'Use integer minor units from 0 to 2147483647 and a three-letter ISO currency.']);
            }
            if ($offer->exists && $offer->isDirty('track_id')) {
                throw ValidationException::withMessages(['track_id' => 'An offer belongs to one track. Create a new offer to change the track.']);
            }
        });
    }
}
