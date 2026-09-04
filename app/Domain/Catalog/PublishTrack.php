<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishTrack
{
    public function unpublish(Track $track, User $actor): Track
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($track, $actor) {
            $locked = Track::query()->lockForUpdate()->findOrFail($track->id);
            $locked->update(['status' => 'draft']);
            AuditEvent::record('catalog.track.unpublished', $locked, [], $actor->id);

            return $locked;
        });
    }

    public function handle(Track $track, User $actor): Track
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($track, $actor) {
            $locked = Track::query()->lockForUpdate()->findOrFail($track->id);
            $blockers = app(PublicationReadiness::class)->blockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['publication' => implode(' ', $blockers)]);
            }
            $locked->update(['status' => 'published', 'published_at' => now()]);
            AuditEvent::record('catalog.track.published', $locked, [], $actor->id);

            return $locked;
        });
    }
}
