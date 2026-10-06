<?php

namespace App\Domain\Memberships\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Private identity anchor; version snapshots supply all policy and display values. */
final class MembershipPlan extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Membership plan identity is immutable.'));
        self::deleting(fn () => throw new LogicException('Membership plan identity is retained.'));
    }
}
