<?php

namespace App\Domain\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class OrderLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['line_hash'];

    protected function casts(): array
    {
        return ['order_id' => 'integer', 'quote_line_id' => 'integer', 'offer_revision_id' => 'integer', 'position' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Order line evidence is immutable.'));
        static::deleting(fn () => throw new LogicException('Order line evidence must be retained.'));
    }
}
