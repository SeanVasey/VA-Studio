<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\ExclusiveActivation;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Commerce\Inventory\ExclusiveSelectionPolicy;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Activate one exact prepared revision for explicitly configured local/test selection. */
final class ActivateExclusiveOffer
{
    public function handle(Offer $offer, int $expectedRevisionId, User $actor): ExclusiveActivation
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        $policy = app(ExclusiveSelectionPolicy::class)->current();

        return DB::transaction(function () use ($offer, $expectedRevisionId, $actor, $policy) {
            $track = Track::whereKey($offer->track_id)->lockForUpdate()->firstOrFail();
            $locked = Offer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $revision = $locked->currentRevision()->first();
            if ($locked->track_id !== $track->id || ! $revision || $revision->id !== $expectedRevisionId) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            \App\Domain\Rights\Models\LicenseVersion::whereKey($revision->license_version_id)->lockForUpdate()->first();
            $track->rightsDeclarations()->latest('id')->lockForUpdate()->first();
            $scope = RightsScope::whereKey($revision->snapshot['inventory']['scope_id'] ?? 0)->lockForUpdate()->first();
            $readiness = app(PublicationReadiness::class);
            if (! $scope || $readiness->preparedExclusiveBlockers($locked, $revision) !== []) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            // Every currently selectable sibling needs its own explicit exact-revision link.
            // The track lock serializes sibling publication; no association is inferred or backfilled.
            $siblings = Offer::where('track_id', $track->id)->where('is_active', true)->pluck('current_revision_id');
            if (RightsScopeOffer::whereIn('offer_revision_id', $siblings)->where('rights_scope_id', $scope->id)->count() !== $siblings->count()) {
                throw new QuoteException('INVENTORY_SCOPE_UNAVAILABLE', 409);
            }
            app(VerifyOfferFiles::class)->revision($revision);
            if ($readiness->preparedExclusiveBlockers($locked, $revision) !== []) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            // Current locking reads after the shared scope lock. Never cancel a non-exclusive pending attempt.
            $occupied = InventoryReservation::query()->select('inventory_reservations.*')->join('inventory_claims',
                'inventory_claims.inventory_reservation_id', '=', 'inventory_reservations.id')
                ->where('inventory_claims.rights_scope_id', $scope->id)
                ->whereIn('state', ['held', 'pending'])->orderBy('inventory_reservations.id')->lockForUpdate()->get();
            if ($occupied->contains(fn ($reservation) => $reservation->state === 'pending' || $reservation->expires_at->isFuture())) {
                throw new QuoteException('INVENTORY_UNAVAILABLE', 409);
            }
            $evidence = app(ExclusiveActivationEvidence::class);
            $activation = ExclusiveActivation::where('offer_revision_id', $revision->id)->first();
            if ($activation) {
                $activation = $evidence->current($revision);
            } else {
                $at = now()->toImmutable()->utc()->startOfSecond();
                $snapshot = $evidence->snapshot($revision, $policy, $actor->id, $at->toIso8601ZuluString());
                $activation = ExclusiveActivation::create(['offer_revision_id' => $revision->id, 'rights_scope_id' => $scope->id,
                    'activated_by' => $actor->id, 'snapshot' => $snapshot, 'snapshot_hash' => CanonicalJson::hash($snapshot),
                    'canonicalization_version' => CanonicalJson::VERSION, 'created_at' => $at]);
            }
            if (! $locked->is_active) {
                $locked->update(['is_active' => true]);
                AuditEvent::record('catalog.offer.exclusive_activated', $locked,
                    ['revision_id' => $revision->id, 'activation_id' => $activation->id, 'snapshot_hash' => $activation->snapshot_hash], $actor->id);
            }

            return $activation;
        }, 5);
    }
}
