<?php

namespace App\Domain\Rights\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class LicenseVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['structured_terms' => 'array', 'approved_at' => 'datetime', 'published_at' => 'datetime'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LicenseTemplate::class, 'license_template_id');
    }

    public function features(): array
    {
        return $this->structured_terms['features'] ?? [];
    }

    public function requiredAssetRoles(): array
    {
        return $this->structured_terms['required_asset_roles'] ?? [];
    }

    protected static function booted(): void
    {
        static::updating(function (LicenseVersion $version) {
            if (in_array($version->getOriginal('status'), ['legal_review', 'approved'], true) && $version->isDirty(['authored_source', 'structured_terms', 'author_id', 'license_template_id', 'version'])) {
                throw ValidationException::withMessages(['license' => 'Reviewed legal content is frozen. Create a successor draft to change it.']);
            }
            if ($version->getOriginal('published_at') !== null) {
                throw ValidationException::withMessages(['license' => 'Published license versions are immutable. Create a successor version.']);
            }
        });
        static::deleting(function (LicenseVersion $version) {
            if ($version->published_at !== null) {
                throw ValidationException::withMessages(['license' => 'Published license versions cannot be deleted.']);
            }
        });
        static::saving(function (LicenseVersion $version) {
            if ($version->status === 'published' && ($version->approved_at === null || $version->approved_by === null || $version->approved_by === $version->author_id || ! $version->approval_reference || ! $version->renderer_version || ! preg_match('/^[a-f0-9]{64}$/', $version->render_fixture_hash ?? '') || $version->source_hash !== hash('sha256', $version->authored_source) || $version->model_hash !== hash('sha256', json_encode($version->structured_terms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)) || ! $version->published_at)) {
                throw ValidationException::withMessages(['license' => 'Publishing requires separate approval, evidence reference, source/model hashes and a pinned rendered fixture.']);
            }
        });
    }
}
