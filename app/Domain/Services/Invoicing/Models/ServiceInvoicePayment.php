<?php

namespace App\Domain\Services\Invoicing\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Immutable verified service payment: the only input to a service project's payment state. */
final class ServiceInvoicePayment extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_invoice_id' => 'integer', 'project_id' => 'integer', 'amount_minor' => 'integer', 'verified_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Retain verified service payments.'));
        self::deleting(fn () => throw new LogicException('Retain verified service payments.'));
    }
}
