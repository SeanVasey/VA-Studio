<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Retained local/test finalization evidence; no active delivery authority is created here. */
final class PendingEntitlement extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['asset_hash'];

    protected $attributes = ['state' => 'pending'];

    protected function casts(): array
    {
        return ['license_grant_id' => 'integer', 'media_asset_id' => 'integer', 'size_bytes' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(LicenseGrant::class, 'license_grant_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Finalization evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Finalization evidence must be retained.'));
    }
}
