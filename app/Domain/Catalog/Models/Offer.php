<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Rights\Models\LicenseVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Offer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['deliverable_asset_ids' => 'array', 'is_active' => 'boolean'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function licenseVersion(): BelongsTo
    {
        return $this->belongsTo(LicenseVersion::class);
    }

    protected static function booted(): void
    {
        static::saving(function (Offer $offer) {
            if ($offer->price_minor < 0 || ! preg_match('/^[A-Z]{3}$/', $offer->currency)) {
                throw ValidationException::withMessages(['price_minor' => 'Use nonnegative integer minor units and a three-letter ISO currency.']);
            }
        });
    }
}
