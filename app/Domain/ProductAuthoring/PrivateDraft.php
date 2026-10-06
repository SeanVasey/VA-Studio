<?php

namespace App\Domain\ProductAuthoring;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

abstract class PrivateDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $record): void {
            if ($record->isDirty(['id', 'creation_review_hash', 'created_by', 'created_at'])) {
                throw ValidationException::withMessages(['title' => 'Retain the original private draft identity.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['title' => 'Retain this private draft and its complete history.']));
    }
}
