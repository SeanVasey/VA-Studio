<?php

namespace App\Domain\Rights\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class LicenseTemplate extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (LicenseTemplate $template) {
            if ($template->versions()->where('status', '!=', 'draft')->exists()) {
                throw ValidationException::withMessages(['template' => 'Reviewed template identity is frozen. Create a successor template.']);
            }
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(LicenseVersion::class);
    }
}
