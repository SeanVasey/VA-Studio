<?php

namespace App\Domain\Albums\Models;

use App\Domain\Albums\ReleaseType;
use App\Domain\SiteBuilder\Models\SiteImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Staff-entered metadata of one of Sean's own releases. Written only by App\Domain\Albums\AlbumReleases. */
class AlbumRelease extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'release_type' => ReleaseType::class, 'release_date' => 'date:Y-m-d', 'listening_links' => 'array',
            'is_published' => 'boolean', 'revision' => 'integer', 'published_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<AlbumReleaseTrack, $this> */
    public function tracks(): HasMany
    {
        return $this->hasMany(AlbumReleaseTrack::class)->orderBy('position');
    }

    /** @return BelongsTo<SiteImage, $this> */
    public function artwork(): BelongsTo
    {
        return $this->belongsTo(SiteImage::class, 'artwork_image_id');
    }
}
