<?php

namespace App\Domain\SiteBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One re-encoded size of a site image, stored read-only in private storage. */
class SiteImageVariant extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['site_image_id' => 'integer', 'width' => 'integer', 'height' => 'integer', 'size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SiteImage, $this> */
    public function image(): BelongsTo
    {
        return $this->belongsTo(SiteImage::class, 'site_image_id');
    }

    public function mimeType(): string
    {
        return $this->format === 'webp' ? 'image/webp' : 'image/jpeg';
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Site image variants are immutable.'));
        static::deleting(fn () => throw new LogicException('Site image variants must be retained.'));
    }
}
