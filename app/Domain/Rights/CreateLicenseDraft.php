<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CreateLicenseDraft
{
    public function handle(LicenseTemplate $template, array $content, User $actor, ?LicenseVersion $predecessor = null): LicenseVersion
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        $content = app(LicenseContent::class)->validate($content);

        return DB::transaction(function () use ($template, $content, $actor, $predecessor) {
            $locked = LicenseTemplate::query()->lockForUpdate()->findOrFail($template->id);
            if ($predecessor !== null) {
                $predecessor = LicenseVersion::query()->findOrFail($predecessor->id);
                if ($predecessor->license_template_id !== $locked->id) {
                    throw ValidationException::withMessages(['license' => 'The predecessor must belong to this template.']);
                }
            }
            $version = LicenseVersion::create($content + [
                'license_template_id' => $locked->id,
                'version' => ((int) $locked->versions()->max('version')) + 1,
                'author_id' => $actor->id, 'content_author_ids' => array_values(array_unique([...($predecessor?->content_author_ids ?? ($predecessor ? [$predecessor->author_id] : [])), $actor->id])), 'status' => 'draft', 'predecessor_id' => $predecessor?->id,
            ]);
            AuditEvent::record('rights.license.draft_created', $version, ['predecessor_id' => $predecessor?->id], $actor->id);

            return $version;
        });
    }
}
