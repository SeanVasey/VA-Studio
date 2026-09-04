<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class OfferRevision extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'price_minor' => 'integer', 'revision' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['offer' => 'Published commercial revisions are immutable. Publish a successor revision.']));
        static::deleting(fn () => throw ValidationException::withMessages(['offer' => 'Published commercial revisions must be retained. Deactivate the offer instead.']));
    }
}
