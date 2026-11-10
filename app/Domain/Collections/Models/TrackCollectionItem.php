<?php

namespace App\Domain\Collections\Models;

use App\Domain\Catalog\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One ordered reference from a collection to a track. The track alone decides whether it can be shown or sold. */
class TrackCollectionItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'track_id' => 'integer'];
    }

    /** @return BelongsTo<Track, $this> */
    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
