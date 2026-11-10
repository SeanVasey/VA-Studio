<?php

namespace App\Domain\Albums\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row of a release's tracklist: a title and its length. Not a licensable track. */
class AlbumReleaseTrack extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'duration_seconds' => 'integer'];
    }

    /** @return BelongsTo<AlbumRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(AlbumRelease::class, 'album_release_id');
    }
}
