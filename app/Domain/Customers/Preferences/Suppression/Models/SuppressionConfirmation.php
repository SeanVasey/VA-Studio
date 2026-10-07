<?php

namespace App\Domain\Customers\Preferences\Suppression\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SuppressionConfirmation extends Model
{
    protected $table = 'customer_suppression_confirmations';

    protected $guarded = ['id'];

    protected $hidden = ['customer_account_id', 'recipient_hmac', 'recipient_ciphertext', 'binding_ciphertext', 'receipt_ciphertext'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['attempt_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Suppression evidence is retained.'));
        self::deleting(fn () => throw new LogicException('Suppression evidence is retained.'));
    }
}
