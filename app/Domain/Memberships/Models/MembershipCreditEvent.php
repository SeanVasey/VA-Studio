<?php

namespace App\Domain\Memberships\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class MembershipCreditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_balance' => 'array', 'after_balance' => 'array'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Credit movements are append-only.'));
        self::deleting(fn () => throw new LogicException('Credit movements are retained.'));
    }
}
