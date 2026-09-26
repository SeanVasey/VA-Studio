<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Commerce\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Versioned local/test access switch; it never changes a license or retained fulfillment proof. */
final class TestDeliveryControl extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $attributes = ['blocked' => true, 'control_version' => 0];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'test_fulfillment_activation_id' => 'integer', 'blocked' => 'boolean',
            'control_version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    public function order(): BelongsTo { return $this->belongsTo(Order::class, 'order_id'); }
    public function activation(): BelongsTo { return $this->belongsTo(TestFulfillmentActivation::class, 'test_fulfillment_activation_id'); }

    protected static function booted(): void
    {
        static::updating(function (self $control): void {
            if ($control->isDirty(['id', 'public_id', 'order_id', 'test_fulfillment_activation_id', 'created_at'])) {
                throw new LogicException('Test delivery control identity is immutable.');
            }
            if (! $control->isDirty('blocked') || $control->control_version !== $control->getOriginal('control_version') + 1) {
                throw new LogicException('Test delivery controls require one versioned state change.');
            }
        });
        static::deleting(fn () => throw new LogicException('Test delivery control evidence must be retained.'));
    }
}
