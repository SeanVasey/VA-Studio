<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/** Staff-only composition. A selected draft version does not publish or offer a product. */
class ProductDraft extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'created_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $draft): void {
            if ($draft->isDirty(['id', 'kind', 'created_by', 'created_at'])) {
                throw ValidationException::withMessages(['title' => 'Retain the original product draft identity.']);
            }
        });
        static::deleting(fn () => throw ValidationException::withMessages(['title' => 'Retain this product draft and its version history.']));
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ProductDraftVersion::class);
    }
}
