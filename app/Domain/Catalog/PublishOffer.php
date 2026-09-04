<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\VerifiedMedia;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishOffer
{
    public function handle(Offer $offer, User $actor): OfferRevision
    {
        Gate::forUser($actor)->authorize('administer-catalog');

        return DB::transaction(function () use ($offer, $actor) {
            $trackId = Offer::query()->findOrFail($offer->id)->track_id;
            $track = Track::query()->lockForUpdate()->findOrFail($trackId);
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $locked->licenseVersion()->lockForUpdate()->first();
            $track->rightsDeclarations()->latest('id')->lockForUpdate()->first();
            $blockers = app(PublicationReadiness::class)->draftBlockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['offer' => implode(' ', $blockers)]);
            }
            // Publication pins a new commercial promise: do not reuse the public-read digest cache.
            $preview = $track->assets()->where('role', 'preview_tagged')->where('status', 'ready')->latest('id')->firstOrFail();
            $assets = MediaAsset::query()->whereIn('id', [...$locked->deliverable_asset_ids, $preview->id])->get();
            foreach ($assets as $asset) {
                $path = app(VerifiedMedia::class)->path($asset);
                $hash = $path ? @hash_file('sha256', $path) : false;
                if (! $path || ! is_string($hash) || ! hash_equals($asset->sha256, $hash) || @filesize($path) !== $asset->size_bytes) {
                    throw ValidationException::withMessages(['offer' => 'Publication requires a fresh digest match for every deliverable and preview.']);
                }
            }
            $snapshot = app(OfferSnapshot::class)->capture($locked);
            $hash = CanonicalJson::hash($snapshot);
            $current = $locked->currentRevision()->first();
            if ($current && hash_equals($current->snapshot_hash, $hash)) {
                if (! $locked->is_active) {
                    $locked->update(['is_active' => true]);
                    AuditEvent::record('catalog.offer.activated', $locked, ['revision_id' => $current->id], $actor->id);
                }

                return $current;
            }
            $revision = OfferRevision::create([
                'offer_id' => $locked->id, 'track_id' => $track->id, 'license_version_id' => $locked->license_version_id,
                'rights_declaration_id' => $snapshot['rights']['id'], 'revision' => ($locked->revisions()->max('revision') ?? 0) + 1,
                'price_minor' => $locked->price_minor, 'currency' => $locked->currency, 'snapshot' => $snapshot, 'snapshot_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION, 'published_by' => $actor->id, 'published_at' => now(),
            ]);
            $locked->update(['current_revision_id' => $revision->id, 'is_active' => true]);
            AuditEvent::record('catalog.offer.revision_published', $locked, ['revision_id' => $revision->id, 'revision' => $revision->revision, 'snapshot_hash' => $hash], $actor->id);

            return $revision;
        });
    }
}
