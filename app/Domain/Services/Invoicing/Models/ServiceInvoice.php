<?php

namespace App\Domain\Services\Invoicing\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Coordination row for one Stripe test-mode invoice. It advances monotonically and is never deleted. */
final class ServiceInvoice extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return ['project_id' => 'integer', 'revision' => 'integer', 'amount_minor' => 'integer', 'days_until_due' => 'integer',
            'payment_failures' => 'integer', 'last_payment_failed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::deleting(fn () => throw new LogicException('Retain service invoices.'));
    }
}
