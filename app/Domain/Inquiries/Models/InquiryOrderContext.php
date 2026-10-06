<?php

namespace App\Domain\Inquiries\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class InquiryOrderContext extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['order_hash', 'inquiry_hash', 'context_hash'];

    protected function casts(): array
    {
        return ['inquiry_id' => 'integer', 'order_id' => 'integer', 'schema_version' => 'integer', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Order inquiry context is immutable.'));
        self::deleting(fn () => throw new LogicException('Order inquiry context requires an approved retention workflow.'));
    }
}
