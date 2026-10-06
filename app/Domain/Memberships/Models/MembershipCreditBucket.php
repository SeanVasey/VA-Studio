<?php

namespace App\Domain\Memberships\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One immutable synthetic source award; never a payment or production entitlement. */
final class MembershipCreditBucket extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Credit source evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Credit source evidence is retained.'));
    }
}
