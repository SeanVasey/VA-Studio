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
            $terms = $locked->structured_terms;
            if (! is_array($terms['features'] ?? null) || ! is_array($terms['required_asset_roles'] ?? null) || empty($terms['required_asset_roles'])) {
                throw ValidationException::withMessages(['license' => 'Structured terms need features and required_asset_roles arrays.']);
            }
            $locked->update(['status' => 'published', 'published_at' => now(), 'source_hash' => hash('sha256', $locked->authored_source), 'model_hash' => hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES))]);
            AuditEvent::record('rights.license.published', $locked, ['source_hash' => $locked->source_hash, 'model_hash' => $locked->model_hash], $actor->id);

            return $locked;
        });
    }
}
