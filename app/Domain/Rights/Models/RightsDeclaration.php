<?php

namespace App\Domain\Rights\Models;

use App\Domain\Catalog\Models\Track;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class RightsDeclaration extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (RightsDeclaration $declaration) {
            if ($declaration->getOriginal('status') === 'verified') {
                throw ValidationException::withMessages(['rights' => 'Verified rights evidence is immutable. Record a new declaration.']);
            }
        });
    }

    protected function casts(): array
    {
        return ['verified_at' => 'datetime'];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}
