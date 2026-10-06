<?php

namespace App\Domain\Inquiries\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class InquiryMessage extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['body', 'request_key', 'payload_hash', 'actor_user_id'];

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'created_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Inquiry messages are immutable.'));
        self::deleting(fn () => throw new LogicException('Inquiry messages require an approved retention workflow.'));
    }
}
