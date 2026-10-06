<?php

namespace App\Domain\Memberships\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class MembershipPlanVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['membership_plan_id' => 'integer', 'number' => 'integer', 'policy' => 'array'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Membership plan versions are immutable.'));
        self::deleting(fn () => throw new LogicException('Membership plan versions are retained.'));
    }
}
