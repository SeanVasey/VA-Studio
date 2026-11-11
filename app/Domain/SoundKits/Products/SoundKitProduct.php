<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Staff-managed sound-kit product. Written only by {@see SoundKitProducts}. Carries no customer, payment or entitlement data. */
class SoundKitProduct extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['sound_kit_draft_id' => 'integer', 'sound_kit_revision_id' => 'integer', 'rights_cleared_revision_id' => 'integer', 'license_version_id' => 'integer',
            'is_published' => 'boolean', 'revision' => 'integer', 'price_minor' => 'integer', 'published_at' => 'immutable_datetime',
            'rights_cleared_at' => 'immutable_datetime', 'offer_approved_at' => 'immutable_datetime',
            'cover_image_id' => 'integer', 'demo_id' => 'integer', 'cover_approved_at' => 'immutable_datetime', 'demo_approved_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SoundKitDraft, $this> */
    public function draft(): BelongsTo
    {
        return $this->belongsTo(SoundKitDraft::class, 'sound_kit_draft_id');
    }

    /** @return BelongsTo<SoundKitDemo, $this> */
    public function demo(): BelongsTo
    {
        return $this->belongsTo(SoundKitDemo::class, 'demo_id');
    }

    /** @return BelongsTo<SoundKitRevision, $this> */
    public function archiveRevision(): BelongsTo
    {
        return $this->belongsTo(SoundKitRevision::class, 'sound_kit_revision_id');
    }

    /** @return BelongsTo<LicenseVersion, $this> */
    public function licenseVersion(): BelongsTo
    {
        return $this->belongsTo(LicenseVersion::class, 'license_version_id');
    }
}
