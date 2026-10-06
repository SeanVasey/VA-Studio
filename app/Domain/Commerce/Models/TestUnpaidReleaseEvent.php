<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestUnpaidReleaseEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['request_id'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'sequence' => 'integer', 'review_sequence' => 'integer', 'actor_id' => 'integer', 'observed_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Unpaid release evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Unpaid release evidence must be retained.'));
    }
}
