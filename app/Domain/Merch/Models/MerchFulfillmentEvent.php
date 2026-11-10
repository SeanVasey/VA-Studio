<?php

namespace App\Domain\Merch\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Receipt of one Printful webhook event. Immutable. */
final class MerchFulfillmentEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['merch_fulfillment_id' => 'integer', 'occurred_at' => 'immutable_datetime', 'received_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Fulfillment event evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Fulfillment event evidence must be retained.'));
    }
}
