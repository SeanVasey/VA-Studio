<?php

namespace App\Domain\SoundKits\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class SoundKitUploadSession extends Model
{
    public const IDENTITY = ['id', 'public_id', 'actor_id', 'sound_kit_draft_id', 'expected_version', 'profile_sha256',
        'original_name', 'size_bytes', 'sha256', 'expires_at', 'created_at'];

    protected $guarded = ['id'];

    protected $hidden = ['parts'];

    protected function casts(): array
    {
        return ['id' => 'integer', 'actor_id' => 'integer', 'sound_kit_draft_id' => 'integer', 'expected_version' => 'integer',
            'size_bytes' => 'integer', 'received_bytes' => 'integer', 'revision_id' => 'integer', 'parts' => 'array',
            'expires_at' => 'immutable_datetime', 'cleaned_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $session): void {
            if ($session->isDirty(self::IDENTITY) || (in_array($session->getRawOriginal('status'), ['completed', 'cancelled'], true)
                && $session->isDirty(['status', 'revision_id', 'parts', 'received_bytes']))) {
                throw new LogicException('Retain the kit upload identity and terminal result.');
            }
        });
        self::deleting(fn () => throw new LogicException('Retain the kit upload session evidence.'));
    }
}
