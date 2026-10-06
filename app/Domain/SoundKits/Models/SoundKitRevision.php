<?php

namespace App\Domain\SoundKits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class SoundKitRevision extends Model
{
    public const SOURCE_FIELDS = ['id', 'public_id', 'sound_kit_draft_id', 'number', 'command_sha256', 'original_name',
        'source_path', 'source_sha256', 'source_size_bytes', 'mime_type', 'profile', 'profile_sha256', 'description_snapshot', 'uploaded_by', 'created_at'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_draft_id' => 'integer', 'number' => 'integer', 'source_size_bytes' => 'integer',
            'profile' => 'array', 'description_snapshot' => 'array', 'manifest' => 'array', 'evidence' => 'array',
            'uploaded_by' => 'integer', 'requested_by' => 'integer', 'attempts' => 'integer',
            'archive_size_bytes' => 'integer', 'claimed_until' => 'datetime', 'verified_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if (in_array($revision->getRawOriginal('status'), ['ready', 'failed'], true) || $revision->isDirty(self::SOURCE_FIELDS)) {
                throw ValidationException::withMessages(['upload' => 'Retain this archive revision. Upload a new revision for changed bytes or metadata.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['upload' => 'Retain the private archive and its processing evidence.']));
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(SoundKitDraft::class, 'sound_kit_draft_id');
    }
}
