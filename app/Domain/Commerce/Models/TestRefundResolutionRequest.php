<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestRefundResolutionRequest extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id'];

    protected function casts(): array
    {
        return ['order_finalization_id' => 'integer', 'actor_id' => 'integer', 'expected_sequence' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Refund resolution evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Refund resolution evidence must be retained.'));
    }
}
