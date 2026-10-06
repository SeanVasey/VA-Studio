<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ProductDraftVersion extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['product_draft_id' => 'integer', 'number' => 'integer', 'manifest' => 'array',
            'source_version_id' => 'integer', 'created_by' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['title' => 'Product draft versions are immutable. Save a new version.']));
        static::deleting(fn () => throw ValidationException::withMessages(['title' => 'Retain the product draft version history.']));
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProductDraftMember::class);
    }
}
