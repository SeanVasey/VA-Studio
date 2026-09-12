<?php

namespace App\Domain\Rights\Models;

use App\Domain\Rights\VerifiedLicense;
use App\Domain\Rights\LicenseTerms;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class LicenseVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'structured_terms' => 'array', 'content_author_ids' => 'array', 'submission_payload' => 'array', 'terms_schema_version' => 'integer',
            'approved_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime', 'submitted_at' => 'immutable_datetime',
            'effective_from' => 'immutable_datetime', 'effective_until' => 'immutable_datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(LicenseTemplate::class, 'license_template_id');
    }

    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_id');
    }

    public function reviewEvidence(): HasOne
    {
        return $this->hasOne(LicenseReviewEvidence::class);
    }

    public function features(): array
    {
        if (in_array($this->structured_terms['schema_version'] ?? null, [2, 3], true)) {
            return array_values(app(LicenseTerms::class)->statements($this->structured_terms));
        }

        return $this->structured_terms['features'] ?? [];
    }

    public function requiredAssetRoles(): array
    {
        return $this->structured_terms['required_asset_roles'] ?? [];
    }

    protected static function booted(): void
    {
        static::creating(function (LicenseVersion $version) {
            if (($version->status ?? 'draft') !== 'draft') {
                throw ValidationException::withMessages(['license' => 'Create a draft and use the review lifecycle.']);
            }
        });
        static::updating(function (LicenseVersion $version) {
            $original = $version->getOriginal('status');
            if ($version->getOriginal('published_at') !== null || $original === 'published') {
                throw ValidationException::withMessages(['license' => 'Published license versions are immutable. Create a successor version.']);
            }
            if (in_array($original, ['legal_review', 'approved'], true)) {
                $allowed = $original === 'legal_review' ? ['status', 'approved_by', 'approved_at', 'approval_reference', 'updated_at'] : ['status', 'published_at', 'updated_at'];
                if (array_diff(array_keys($version->getDirty()), $allowed)) {
                    throw ValidationException::withMessages(['license' => 'Reviewed content and evidence are frozen. Create a successor draft.']);
                }
            }
            $transitions = ['draft' => ['draft', 'legal_review'], 'legal_review' => ['approved'], 'approved' => ['published']];
            if (! in_array($version->status, $transitions[$original] ?? [], true)) {
                throw ValidationException::withMessages(['license' => 'This license lifecycle transition is not allowed.']);
            }
            if ($version->status === 'legal_review') {
                app(VerifiedLicense::class)->assertSubmitted($version);
            }
            if (in_array($version->status, ['approved', 'published'], true)) {
                app(VerifiedLicense::class)->assertReviewed($version);
            }
            if ($version->status === 'published' && ! $version->published_at) {
                throw ValidationException::withMessages(['license' => 'Publication needs its timestamp.']);
            }
        });
        static::deleting(function (LicenseVersion $version) {
            throw ValidationException::withMessages(['license' => 'Allocated license versions are retained so their numbers cannot be reused.']);
        });
    }
}
