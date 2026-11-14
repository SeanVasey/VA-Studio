<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\MediaProcessingRun;
use App\Domain\Media\Models\StemsRecording;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Support\PublicReadMemo;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Reads, for a page of candidate tracks at once, the rows their public eligibility checks would otherwise read one at a time, and
 * primes PublicReadMemo with them under the same keys and with the same values those single reads produce. It decides nothing:
 * PublicationReadiness, VerifiedMedia, RecordingAssociation and SelectionInventory still make every check, on these rows.
 *
 * Every value primed here equals its single read's: the latest row by id where the single read takes latest('id')->first(), null
 * where it finds nothing, and the exact related rows (a run's outputs are read by run, not by track) where it reads a relation.
 * Rows are primed only inside the scope that read them and only if nothing was written since $generation (PublicReadMemo::prime).
 */
final class ListingPrefetch
{
    /** @param  Collection<int, Track>  $tracks  candidates hydrated with their `assets` relation in this scope since $generation */
    public function prime(Collection $tracks, int $generation): void
    {
        if (! PublicReadMemo::active() || $generation !== PublicReadMemo::generation() || $tracks->isEmpty()) {
            return;
        }
        $put = fn (string $key, mixed $value) => PublicReadMemo::prime($key, $value, $generation);
        $trackIds = $tracks->pluck('id')->all();
        $assets = new EloquentCollection($tracks->flatMap(fn (Track $track) => $track->relationLoaded('assets') ? $track->assets : [])->all());
        $byId = $assets->keyBy('id');

        foreach ($tracks as $track) {
            $put('track:'.$track->id, $track);
            foreach (['artwork', 'preview_tagged'] as $role) {
                if ($track->relationLoaded('assets')) {
                    $put('track-ready:'.$track->id.':'.$role, $track->assets->where('role', $role)->where('status', 'ready')->sortByDesc('id')->first());
                }
            }
        }
        foreach ($byId as $id => $asset) {
            $put('media-asset:'.$id, $asset);
        }

        $rights = RightsDeclaration::query()->whereIn('track_id', $trackIds)->orderBy('id')->get()->groupBy('track_id');
        foreach ($trackIds as $id) {
            $put('track-rights:'.$id, $rights->get($id)?->last());
        }

        $runIds = $assets->pluck('processing_run_id')->filter()->unique()->values()->all();
        if ($runIds !== []) {
            $runs = MediaProcessingRun::query()->whereIn('id', $runIds)->get()->keyBy('id');
            $outputs = MediaAsset::query()->whereIn('processing_run_id', $runIds)->get()->groupBy('processing_run_id');
            foreach ($runIds as $id) {
                $put('media-run:'.$id, $runs->get($id));
                $put('media-run-outputs:'.$id, new EloquentCollection($outputs->get($id)?->all() ?? []));
            }
        }

        $stemsIds = $assets->where('role', 'stems_zip')->pluck('id')->all();
        if ($stemsIds !== []) {
            $bindings = StemsRecording::query()->whereIn('stems_asset_id', $stemsIds)->get()->keyBy('stems_asset_id');
            foreach ($stemsIds as $id) {
                $binding = $bindings->get($id);
                // The binding's own relations, when those rows were just read with the track's assets.
                foreach (['master' => 'master_asset_id', 'preview' => 'preview_asset_id'] as $relation => $column) {
                    if ($binding !== null && $byId->has($binding->{$column})) {
                        $binding->setRelation($relation, $byId->get($binding->{$column}));
                    }
                }
                $put('stems-binding:'.$id, $binding);
            }
        }

        $offers = Offer::query()->whereIn('track_id', $trackIds)->where('is_active', true)->get()->groupBy('track_id');
        foreach ($trackIds as $id) {
            $put('track-active-offers:'.$id, new EloquentCollection($offers->get($id)?->all() ?? []));
        }
        $revisionIds = $offers->flatten()->pluck('current_revision_id')->filter()->unique()->values()->all();
        if ($revisionIds !== []) {
            $revisions = OfferRevision::query()->whereIn('id', $revisionIds)->get()->keyBy('id');
            $links = RightsScopeOffer::query()->whereIn('offer_revision_id', $revisionIds)->get()->keyBy('offer_revision_id');
            foreach ($revisionIds as $id) {
                $put('offer-revision:'.$id, $revisions->get($id));
                $put('rights-scope-link:'.$id, $links->get($id));
            }
        }
        foreach (app(SelectionInventory::class)->governedScopesByTrack($trackIds) as $id => $scopes) {
            $put('governed-scopes:'.$id, $scopes);
        }
    }
}
