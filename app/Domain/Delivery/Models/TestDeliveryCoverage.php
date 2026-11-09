<?php

namespace App\Domain\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only coverage log of a v2 transfer: one row per served response, carrying the bytes it handed to the transport and
 * the merged intervals of every distinct byte served so far. The single row whose `completed_authorization_id` is set is the
 * one download this authorization counts. Served is not proof that the recipient stored the bytes.
 */
final class TestDeliveryCoverage extends Model
{
    public $timestamps = false;

    protected $table = 'test_delivery_coverage';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['test_delivery_admission_id' => 'integer', 'test_delivery_authorization_id' => 'integer', 'test_delivery_redemption_id' => 'integer', 'sequence' => 'integer',
            'first_byte' => 'integer', 'last_byte' => 'integer', 'covered_bytes' => 'integer',
            'completed_authorization_id' => 'integer', 'recorded_at' => 'immutable_datetime'];
    }

    public function authorization(): BelongsTo
    {
        return $this->belongsTo(TestDeliveryAuthorization::class, 'test_delivery_authorization_id');
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Test delivery coverage evidence is append-only.'));
        self::deleting(fn () => throw new LogicException('Test delivery coverage evidence must be retained.'));
    }
}
