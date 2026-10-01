<?php

namespace App\Domain\Inquiries\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class CustomerInquiry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'privacy_notice', 'owner_hash', 'request_key', 'payload_hash', 'retention_policy_reference'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'privacy_notice' => 'encrypted', 'version' => 'integer', 'created_at' => 'immutable_datetime', 'updated_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $inquiry): void {
            if (array_diff(array_keys($inquiry->getDirty()), ['state', 'version', 'updated_at']) !== []) {
                throw new LogicException('Inquiry input and receipt evidence are immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('Inquiry deletion requires a separately approved retention workflow.'));
    }
}
