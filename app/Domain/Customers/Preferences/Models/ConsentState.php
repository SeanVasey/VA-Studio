<?php

namespace App\Domain\Customers\Preferences\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ConsentState extends Model
{
    protected $table = 'customer_consent_states';

    protected $guarded = ['id'];

    protected $hidden = ['customer_account_id', 'event_id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['customer_account_id' => 'integer', 'revision' => 'integer', 'event_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $state): void {
            if ($state->isDirty(['id', 'customer_account_id', 'purpose', 'created_at']) || $state->revision !== $state->getOriginal('revision') + 1) {
                throw new LogicException('Consent state requires its retained owner and next revision.');
            }
        });
        self::deleting(fn () => throw new LogicException('Consent state revisions must be retained.'));
    }
}
