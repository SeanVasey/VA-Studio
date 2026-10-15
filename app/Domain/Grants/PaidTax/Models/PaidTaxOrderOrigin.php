<?php

namespace App\Domain\Grants\PaidTax\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class PaidTaxOrderOrigin extends Model
{
    protected $table = 'paid_tax_order_origins';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Retain original paid origin.'));
        self::deleting(fn () => throw new LogicException('Retain original paid origin.'));
    }
}
