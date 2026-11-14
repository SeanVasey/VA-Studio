<?php

namespace App\Domain\Collections\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Staff-ordered browse grouping of tracks. Written only by App\Domain\Collections\TrackCollections. Carries no price. */
class TrackCollection extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean', 'revision' => 'integer', 'published_at' => 'immutable_datetime'];
    }

    /** @return HasMany<TrackCollectionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TrackCollectionItem::class)->orderBy('position');
    }
}
