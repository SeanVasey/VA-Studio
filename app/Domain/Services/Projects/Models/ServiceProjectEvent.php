<?php

namespace App\Domain\Services\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ServiceProjectEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'request_key', 'request_hash', 'actor_id'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'number' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Retain immutable service journey events.'));
        self::deleting(fn () => throw new LogicException('Retain service journey history.'));
    }
}
