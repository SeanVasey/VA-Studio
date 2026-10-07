<?php

namespace App\Domain\Grants\Free\Models;

use App\Domain\Grants\Free\FreeGrantException;
use Illuminate\Database\Eloquent\Model;

/** Operator projection only. All writes belong to the captured domain commands. */
final class FreeDefinition extends Model
{
    protected $table = 'free_definitions';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::creating(fn () => throw new FreeGrantException);
        self::updating(fn () => throw new FreeGrantException);
        self::deleting(fn () => throw new FreeGrantException);
    }
}
