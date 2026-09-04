<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ReviewLicense
{
    public function submit(LicenseVersion $version, User $actor): LicenseVersion
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($version, $actor) {
            $locked = LicenseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['license' => 'Only a draft may be submitted for review.']);
            }
            $locked->update(['status' => 'legal_review']);
            AuditEvent::record('rights.license.review_requested', $locked, [], $actor->id);

            return $locked;
        });
    }

    public function approve(LicenseVersion $version, User $actor, array $evidence): LicenseVersion
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        $evidence = Validator::make($evidence, [
            'approval_reference' => ['required', 'string', 'max:255'],
            'renderer_version' => ['required', 'string', 'max:255'],
            'render_fixture_hash' => ['required', 'regex:/^[a-f0-9]{64}$/'],
        ])->validate();

        return DB::transaction(function () use ($version, $actor, $evidence) {
            $locked = LicenseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'legal_review' || $locked->author_id === $actor->id) {
                throw ValidationException::withMessages(['license' => 'Approval needs a version in legal review and a different authorized reviewer.']);
            }
            $locked->update($evidence + ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            AuditEvent::record('rights.license.approved', $locked, ['approval_reference' => $evidence['approval_reference']], $actor->id);

            return $locked;
        });
    }
}
