<?php

namespace App\Domain\Rights\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class LicenseReviewEvidence extends Model
{
    protected $table = 'license_review_evidence';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['summary_consistency_confirmed' => 'boolean', 'reviewed_at' => 'immutable_datetime'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(LicenseVersion::class, 'license_version_id');
    }

    protected static function booted(): void
    {
        $reject = static function (): never {
            throw ValidationException::withMessages(['license' => 'License review evidence is immutable.']);
        };
        static::updating($reject);
        static::deleting($reject);
    }
}
