<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ProductDraftMember extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_draft_version_id' => 'integer', 'position' => 'integer', 'track_id' => 'integer',
            'metadata_version' => 'integer', 'publication_version' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['track_ids' => 'Retained product members are immutable. Save a new version.']));
        static::deleting(fn () => throw ValidationException::withMessages(['track_ids' => 'Retain the original version membership.']));
    }
}
