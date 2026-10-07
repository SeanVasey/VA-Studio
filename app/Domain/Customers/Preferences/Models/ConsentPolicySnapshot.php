<?php

namespace App\Domain\Customers\Preferences\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ConsentPolicySnapshot extends Model
{
    protected $table = 'customer_consent_policies';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Consent policy originals must be retained.'));
        self::deleting(fn () => throw new LogicException('Consent policy originals must be retained.'));
    }
}
