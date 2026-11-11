<?php

namespace App\Domain\Commerce\RefundDispute\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Read-only view model of a production tax checkout order row for the staff list; the table is append-only and never written here. */
final class ProductionTaxPaidOrder extends Model
{
    public $timestamps = false;

    protected $table = 'production_tax_checkout_orders';

    protected $guarded = ['id'];

    protected $hidden = ['payload_ciphertext', 'payload_hash'];

    protected static function booted(): void
    {
        self::saving(fn () => throw new LogicException('Production tax checkout orders are append-only.'));
        self::deleting(fn () => throw new LogicException('Production tax checkout orders must be retained.'));
    }
}
