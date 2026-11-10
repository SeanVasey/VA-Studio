<?php

namespace App\Domain\SoundKits\Products;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One staff upload of a sound-kit product's demo audio, with its private source, retained scan evidence and prepared preview.
 * Once `ready` or `failed` it never changes and no row is deleted; a replacement is a new row. Written only by
 * {@see SoundKitProductMedia} (upload) and {@see SoundKitDemoProcessor} (processing).
 */
class SoundKitDemo extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_product_id' => 'integer', 'size_bytes' => 'integer', 'attempts' => 'integer', 'preview_size_bytes' => 'integer',
            'duration_seconds' => 'integer', 'uploaded_by' => 'integer', 'waveform' => 'array', 'evidence' => 'array',
            'claimed_until' => 'immutable_datetime', 'processed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SoundKitProduct, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(SoundKitProduct::class, 'sound_kit_product_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $demo): void {
            if (in_array($demo->getRawOriginal('status'), ['ready', 'failed'], true)) {
                throw new LogicException('A processed sound-kit demo is immutable. Upload a new demo.');
            }
        });
        static::deleting(fn () => throw new LogicException('Sound-kit demos must be retained.'));
    }
}
