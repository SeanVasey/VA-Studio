<?php

namespace App\Domain\Grants\Paid\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PaidOrderOrigin extends Model
{
    protected $table = 'paid_order_origins';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Retain original paid origin.'));
        self::deleting(fn () => throw new LogicException('Retain original paid origin.'));
    }
}
