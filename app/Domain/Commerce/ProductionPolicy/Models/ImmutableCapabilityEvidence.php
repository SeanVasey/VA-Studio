<?php

namespace App\Domain\Commerce\ProductionPolicy\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

abstract class ImmutableCapabilityEvidence extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload_ciphertext', 'approval_ciphertext', 'closure_ciphertext'];

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the reviewed immutable capability command.'));
        static::deleting(fn () => throw new LogicException('Retain immutable capability evidence.'));
    }
}
