<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TestPaymentFinancialObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['evidence_ciphertext', 'evidence_hash'];

    protected function casts(): array
    {
        return ['test_payment_exception_event_id' => 'integer', 'observed_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Financial observations are immutable.'));
        self::deleting(fn () => throw new LogicException('Financial observations must be retained.'));
    }
}
