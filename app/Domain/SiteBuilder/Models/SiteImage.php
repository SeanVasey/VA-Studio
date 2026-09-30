<?php

namespace App\Domain\SiteBuilder\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** An uploaded site image and its provenance. Once ready or failed it never changes, and it is never deleted. */
class SiteImage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer', 'width' => 'integer', 'height' => 'integer', 'uploaded_by' => 'integer', 'attempts' => 'integer',
            'evidence' => 'array', 'rights_confirmed_at' => 'immutable_datetime', 'claimed_until' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<SiteImageVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(SiteImageVariant::class)->orderBy('format')->orderBy('width');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** The smallest JPEG, used for staff thumbnails. */
    public function thumbnail(): ?SiteImageVariant
    {
        return $this->status === 'ready' ? $this->variants()->where('format', 'jpeg')->reorder('width')->first() : null;
    }

    protected static function booted(): void
    {
        static::updating(function (self $image): void {
            if (! in_array($image->getOriginal('status'), ['quarantined', 'processing'], true)) {
                throw new LogicException('A processed site image is immutable.');
            }
        });
        static::deleting(fn () => throw new LogicException('Site images must be retained.'));
    }
}
