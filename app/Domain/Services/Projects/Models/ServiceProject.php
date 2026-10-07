<?php

namespace App\Domain\Services\Projects\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ServiceProject extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['service_manifest', 'brief', 'creation_key', 'customer_account_id', 'created_by'];

    protected function casts(): array
    {
        return ['service_manifest' => 'encrypted:array', 'brief' => 'encrypted:array', 'service_version_id' => 'integer', 'customer_account_id' => 'integer'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Retain the original service brief.'));
        self::deleting(fn () => throw new LogicException('Retain service project history.'));
    }
}
