<?php

namespace App\Domain\SoundKits\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SoundKitDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $draft): void {
            if ($draft->isDirty(['id', 'public_id', 'created_by', 'created_at'])) {
                throw ValidationException::withMessages(['title' => 'Retain the original sound-kit identity.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['title' => 'Retain this private kit and its archive history.']));
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SoundKitRevision::class);
    }
}
