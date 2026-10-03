<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestPaymentExceptionEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id'];

    protected function casts(): array
    {
        return ['order_finalization_id' => 'integer', 'sequence' => 'integer', 'actor_id' => 'integer',
            'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Test exception events are immutable.'));
        self::deleting(fn () => throw new LogicException('Test exception events must be retained.'));
    }
}
