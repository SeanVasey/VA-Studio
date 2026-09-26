<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Retained local/test finalization evidence; no active delivery authority is created here. */
final class FulfillmentOutbox extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $table = 'fulfillment_outbox';

    protected $hidden = ['payload'];

    protected $attributes = ['state' => 'pending'];

    protected function casts(): array
    {
        return ['order_finalization_id' => 'integer', 'license_grant_id' => 'integer', 'payload' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function finalization(): BelongsTo
    {
        return $this->belongsTo(OrderFinalization::class, 'order_finalization_id');
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
