<?php

namespace App\Domain\Services\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/** Staff approval of one pinned service draft version for the public service pages. Rows are never deleted. */
final class ServicePublication extends Model
{
    protected $table = 'service_publications';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_draft_id' => 'integer', 'service_draft_version_id' => 'integer', 'price_minor' => 'integer',
            'approved_by' => 'integer', 'withdrawn_by' => 'integer', 'approved_at' => 'datetime', 'withdrawn_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::updating(function (self $record): void {
            if ($record->isDirty(['id', 'service_draft_id', 'created_at'])) {
                throw ValidationException::withMessages(['slug' => 'Retain the original service page identity.']);
            }
        });
        self::deleting(fn () => throw ValidationException::withMessages(['slug' => 'Retain this service page record; withdraw it instead.']));
    }
}
