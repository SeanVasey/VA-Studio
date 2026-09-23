<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Freeze a scope-bound test revision without making an exclusive offer selectable or payable. */
final class PrepareExclusiveOffer
{
    public function handle(Offer $offer, int $scopeId, string $reference, User $actor): OfferRevision
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        InventoryPolicy::requireTestEnvironment();
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,191}\z/D', $reference)) {
            throw ValidationException::withMessages(['inventory' => 'An explicit private scope-link evidence reference is required.']);
        }

        return DB::transaction(function () use ($offer, $scopeId, $reference, $actor) {
            // Same track -> offer -> license/rights -> scope order as existing publication/linkage.
            // Offer track identity is immutable. Avoid a consistent read before these locks.
            $track = Track::whereKey($offer->track_id)->lockForUpdate()->firstOrFail();
            $locked = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->track_id !== (int) $track->id) {
                throw ValidationException::withMessages(['offer' => 'The offer does not belong to the locked track.']);
            }
            $locked->licenseVersion()->lockForUpdate()->first();
            $track->rightsDeclarations()->latest('id')->lockForUpdate()->first();
            $current = $locked->currentRevision()->lockForUpdate()->first();
            if ($locked->is_active || ($current && (($current->snapshot['schema_version'] ?? null) !== 2 ||
                ($current->snapshot['purpose'] ?? null) !== 'test_exclusive_preparation'))) {
                throw ValidationException::withMessages(['offer' => 'Use a separate inactive offer for exclusive preparation; existing commercial revisions are retained.']);
            }
            $readiness = app(PublicationReadiness::class);
            $blockers = $readiness->exclusiveDraftBlockers($locked);
            if ($blockers !== []) { throw ValidationException::withMessages(['offer' => implode(' ', $blockers)]); }
            $scope = RightsScope::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            if ($scope->blocked) {
                throw ValidationException::withMessages(['inventory' => 'The underlying rights scope is administratively blocked.']);
            }
            app(VerifyOfferFiles::class)->handle($locked, $track);
            // Hashing can span a license's effective boundary. Never freeze newly expired terms.
            $blockers = $readiness->exclusiveDraftBlockers($locked);
            if ($blockers !== []) { throw ValidationException::withMessages(['offer' => implode(' ', $blockers)]); }
            $snapshot = app(OfferSnapshot::class)->capture($locked);
            $snapshot['schema_version'] = 2;
            $snapshot['purpose'] = 'test_exclusive_preparation';
            $snapshot['commercial']['type'] = 'exclusive';
            $snapshot['inventory'] = app(ExclusiveOfferScope::class)->capture($scope, $reference);
            $hash = CanonicalJson::hash($snapshot);
            if ($current && hash_equals($current->snapshot_hash, $hash)) {
                $blockers = $readiness->preparedExclusiveBlockers($locked, $current);
                if ($blockers !== []) { throw ValidationException::withMessages(['offer' => implode(' ', $blockers)]); }

                return $current;
            }
            $revision = OfferRevision::create([
                'offer_id' => $locked->id, 'track_id' => $track->id, 'license_version_id' => $locked->license_version_id,
                'rights_declaration_id' => $snapshot['rights']['id'], 'revision' => ($locked->revisions()->orderByDesc('revision')->lockForUpdate()->first()?->revision ?? 0) + 1,
                'price_minor' => $locked->price_minor, 'currency' => $locked->currency, 'snapshot' => $snapshot, 'snapshot_hash' => $hash,
                'canonicalization_version' => CanonicalJson::VERSION, 'published_by' => $actor->id, 'published_at' => now(),
            ]);
            $link = RightsScopeOffer::create(['rights_scope_id' => $scope->id, 'offer_revision_id' => $revision->id,
                'evidence_reference' => $reference, 'linked_by' => $actor->id, 'created_at' => now()->utc()->startOfSecond()]);
            $locked->update(['current_revision_id' => $revision->id, 'is_active' => false]);
            AuditEvent::record('commerce.inventory.offer_linked', $link,
                ['scope_id' => $scope->id, 'offer_revision_id' => $revision->id], $actor->id);
            AuditEvent::record('catalog.offer.exclusive_prepared', $locked,
                ['revision_id' => $revision->id, 'revision' => $revision->revision, 'snapshot_hash' => $hash], $actor->id);

            return $revision;
        }, 5);
    }
}
