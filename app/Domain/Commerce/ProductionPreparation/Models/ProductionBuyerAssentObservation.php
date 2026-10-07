<?php

namespace App\Domain\Commerce\ProductionPreparation\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionBuyerAssentObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload_ciphertext', 'payload_hash', 'request_key'];

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the reviewed staff buyer-report command.'));
        static::deleting(fn () => throw new LogicException('Retain immutable staff buyer-report evidence.'));
    }
}
