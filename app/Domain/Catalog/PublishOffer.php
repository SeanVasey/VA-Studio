<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PublishOffer
{
    public function handle(Offer $offer, User $actor): OfferRevision
    {
        return DB::transaction(function () use ($offer, $actor) {
            // Actor first matches rights writers and precedes later actor-attributed FK inserts.
            // Preserve existing caller nesting; this is not a standalone readiness/apply API.
            $currentActor = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
            if ($currentActor === null) {
                throw new AuthorizationException;
            }
            Gate::forUser($currentActor)->authorize('administer-catalog', [true]);
            $trackId = Offer::query()->findOrFail($offer->id)->track_id;
            $track = Track::query()->lockForUpdate()->findOrFail($trackId);
            $locked = Offer::query()->lockForUpdate()->findOrFail($offer->id);
            $locked->licenseVersion()->lockForUpdate()->first();
            $track->rightsDeclarations()->latest('id')->lockForUpdate()->first();
            $blockers = app(PublicationReadiness::class)->draftBlockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['offer' => implode(' ', $blockers)]);
            }
            app(VerifyOfferFiles::class)->handle($locked, $track);
            $snapshot = app(OfferSnapshot::class)->capture($locked);
            $hash = CanonicalJson::hash($snapshot);
            $current = $locked->currentRevision()->first();
            if ($current && hash_equals($current->snapshot_hash, $hash)) {
                if (! $locked->is_active) {
                    $locked->update(['is_active' => true]);
                    AuditEvent::record('catalog.offer.activated', $locked, ['revision_id' => $current->id], $currentActor->id);
                }

                return $current;
            }
            $revision = OfferRevision::create([
                'offer_id' => $locked->id, 'track_id' => $track->id, 'license_version_id' => $locked->license_version_id,
                'rights_declaration_id' => $snapshot['rights']['id'], 'revision' => ($locked->revisions()->max('revision') ?? 0) + 1,
                'price_minor' => $locked->price_minor, 'currency' => $locked->currency, 'snapshot' => $snapshot, 'snapshot_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION, 'published_by' => $currentActor->id, 'published_at' => now(),
            ]);
            $locked->update(['current_revision_id' => $revision->id, 'is_active' => true]);
            AuditEvent::record('catalog.offer.revision_published', $locked, ['revision_id' => $revision->id, 'revision' => $revision->revision, 'snapshot_hash' => $hash], $currentActor->id);

            return $revision;
        });
    }
}
