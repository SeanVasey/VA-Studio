<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishLicense
{
    public function handle(LicenseVersion $version, User $actor): LicenseVersion
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($version, $actor) {
            $locked = LicenseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'approved') {
                throw ValidationException::withMessages(['license' => 'Only independently approved license versions may be published.']);
            }
            app(VerifiedLicense::class)->assertReviewed($locked);
            $locked->update(['status' => 'published', 'published_at' => now()->startOfSecond()]);
            AuditEvent::record('rights.license.published', $locked, ['submission_hash' => $locked->submission_hash, 'evidence_hash' => $locked->reviewEvidence()->firstOrFail()->evidence_hash], $actor->id);

            return $locked;
        });
    }
}
