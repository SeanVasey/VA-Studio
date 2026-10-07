<?php

namespace App\Domain\Customers\Preferences\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ConsentEvent extends Model
{
    protected $table = 'customer_consent_events';

    protected $guarded = ['id'];

    protected $hidden = ['customer_account_id', 'recipient_hmac', 'recipient_ciphertext'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['customer_account_id' => 'integer', 'revision' => 'integer', 'policy_id' => 'integer', 'affirmative' => 'boolean'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Consent capture events are append-only.'));
        self::deleting(fn () => throw new LogicException('Consent capture events are append-only.'));
    }
}
