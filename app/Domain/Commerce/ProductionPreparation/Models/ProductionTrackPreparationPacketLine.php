<?php

namespace App\Domain\Commerce\ProductionPreparation\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class ProductionTrackPreparationPacketLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(fn () => throw new LogicException('Use the reviewed production packet command.'));
        static::deleting(fn () => throw new LogicException('Retain immutable production preparation evidence.'));
    }
}
