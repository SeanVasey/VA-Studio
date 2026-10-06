<?php

namespace App\Domain\Migration\CatalogOnboarding\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CatalogImportBatch extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['review_ciphertext'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Catalog import evidence is immutable.'));
        self::deleting(fn () => throw new LogicException('Catalog import evidence must be retained.'));
    }
}
