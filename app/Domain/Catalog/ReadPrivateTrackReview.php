<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\VerifiedMedia;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use LogicException;

final class ReadPrivateTrackReview
{
    /** Complete every private read before releasing the current staff authority lock. */
    public function handle(int $trackId, User $actor): array
    {
        request()->attributes->set('_track_private_review', true);
        // Readiness uses ordinary eager reads: an existing MySQL RR view must not survive here.
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Private track review requires a standalone transaction.');
        }

        return DB::transaction(function () use ($trackId, $actor): array {
            $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($current === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($current)->authorize('administer-catalog', [true]);
            if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
                throw new AuthorizationException('Admin multi-factor authentication is required.');
            }
            if ($trackId < 1) {
                throw (new ModelNotFoundException)->setModel(Track::class, [$trackId]);
            }
            $track = Track::query()->lockForUpdate()->findOrFail($trackId);
            $blockers = app(PublicationReadiness::class)->blockers($track);

            return [
                'track' => $track->only(['id', 'metadata_version', 'status', 'title', 'slug', 'artist', 'bpm',
                    'musical_key', 'genre', 'mood', 'tags', 'description']),
                'readiness' => ['ready' => $blockers === [], 'blockers' => $blockers],
                'artwork' => $this->derivative($track, 'artwork'),
                'preview_tagged' => $this->derivative($track, 'preview_tagged'),
            ];
        });
    }

    private function derivative(Track $track, string $role): array
    {
        // Match ordinary readiness. A damaged current revision never falls back to an older one.
        $asset = $track->assets()->where('role', $role)->where('status', 'ready')->latest('id')->first();
        if ($asset === null) {
            return ['state' => 'missing', 'asset_id' => null, 'url' => null];
        }
        if (! app(VerifiedMedia::class)->available($asset)) {
            return ['state' => 'unavailable', 'asset_id' => (int) $asset->id, 'url' => null];
        }

        return ['state' => 'available', 'asset_id' => (int) $asset->id,
            'url' => route('filament.admin.media.preview', ['asset' => $asset->id], absolute: false)];
    }
}
