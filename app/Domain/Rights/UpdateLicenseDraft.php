<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class UpdateLicenseDraft
{
    public function handle(LicenseVersion $version, array $content, User $actor): LicenseVersion
    {
        return DB::transaction(function () use ($version, $content, $actor) {
            // Current authority precedes resource locks and actor-attributed foreign keys.
            $currentActor = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize('administer-catalog', [true]);
            $content = app(LicenseContent::class)->validate($content);
            $locked = LicenseVersion::query()->lockForUpdate()->findOrFail($version->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['license' => 'Only a draft may be edited. Create a successor for reviewed content.']);
            }
            $locked->fill($content);
            if ($locked->isDirty(array_keys($content))) {
                $locked->content_author_ids = array_values(array_unique([...($locked->content_author_ids ?? [$locked->author_id]), $currentActor->id]));
                $locked->author_id = $currentActor->id;
            }
            $locked->save();
            AuditEvent::record('rights.license.draft_updated', $locked, [], $currentActor->id);

            return $locked;
        });
    }
}
