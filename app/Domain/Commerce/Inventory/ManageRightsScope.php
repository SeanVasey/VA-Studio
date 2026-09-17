<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublicationReadiness;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Internal test foundation. An explicit link does not establish legal ownership or publish an exclusive. */
final class ManageRightsScope
{
    public function register(string $key, string $reference, User $actor): RightsScope
    {
        $this->authorize($actor, $reference);
        if (! preg_match('/\A[a-z0-9][a-z0-9._:-]{0,95}\z/D', $key)) { throw new QuoteException('INVALID_QUOTE_REQUEST', 422); }

        return DB::transaction(function () use ($key, $reference, $actor) {
            $id = (string) Str::uuid();
            DB::table('rights_scopes')->insertOrIgnore(['public_id' => $id, 'scope_key' => $key,
                'evidence_reference' => $reference, 'created_by' => $actor->id, 'created_at' => now()->utc()->startOfSecond(),
                'blocked' => false, 'control_version' => 0]);
            $scope = RightsScope::where('scope_key', $key)->lockForUpdate()->first();
            if (! $scope || $scope->evidence_reference !== $reference) { throw new QuoteException('INVENTORY_SCOPE_CONFLICT', 409); }
            if ($scope->public_id === $id) {
                AuditEvent::record('commerce.inventory.scope_registered', $scope, ['public_id' => $id], $actor->id);
            }

            return $scope;
        }, 5);
    }

    public function link(int $scopeId, int $revisionId, string $reference, User $actor): RightsScopeOffer
    {
        $this->authorize($actor, $reference);

        return DB::transaction(function () use ($scopeId, $revisionId, $reference, $actor) {
            $revision = OfferRevision::findOrFail($revisionId);
            $track = Track::whereKey($revision->track_id)->lockForUpdate()->firstOrFail();
            $offer = Offer::whereKey($revision->offer_id)->lockForUpdate()->firstOrFail();
            if ($track->status !== 'published' || ! $offer->is_active || $offer->current_revision_id !== $revision->id ||
                app(PublicationReadiness::class)->blockers($track) !== [] ||
                app(PublicationReadiness::class)->revisionBlockers($offer, $revision) !== []) {
                throw new QuoteException('SELECTION_CHANGED', 409);
            }
            RightsScope::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            $link = RightsScopeOffer::where('offer_revision_id', $revisionId)->lockForUpdate()->first();
            if ($link) {
                if ($link->rights_scope_id !== $scopeId || $link->evidence_reference !== $reference) {
                    throw new QuoteException('INVENTORY_SCOPE_CONFLICT', 409);
                }

                return $link;
            }
            $link = RightsScopeOffer::create(['rights_scope_id' => $scopeId, 'offer_revision_id' => $revisionId,
                'evidence_reference' => $reference, 'linked_by' => $actor->id, 'created_at' => now()->utc()->startOfSecond()]);
            AuditEvent::record('commerce.inventory.offer_linked', $link,
                ['scope_id' => $scopeId, 'offer_revision_id' => $revisionId], $actor->id);

            return $link;
        }, 5);
    }

    public function block(int $scopeId, bool $blocked, int $expectedVersion, string $reference, User $actor): RightsScope
    {
        $this->authorize($actor, $reference);

        return DB::transaction(function () use ($scopeId, $blocked, $expectedVersion, $reference, $actor) {
            $scope = RightsScope::whereKey($scopeId)->lockForUpdate()->firstOrFail();
            if ($scope->control_version !== $expectedVersion) { throw new QuoteException('INVENTORY_CONTROL_CONFLICT', 409); }
            if ($scope->blocked === $blocked) { return $scope; }
            DB::table('rights_scopes')->where('id', $scopeId)->update([
                'blocked' => $blocked, 'control_version' => $expectedVersion + 1,
            ]);
            AuditEvent::record('commerce.inventory.control_changed', $scope, ['blocked' => $blocked,
                'control_version' => $expectedVersion + 1, 'reference_hash' => hash('sha256', $reference)], $actor->id);

            return $scope->refresh();
        }, 5);
    }

    private function authorize(User $actor, string $reference): void
    {
        Gate::forUser($actor)->authorize('administer-catalog');
        InventoryPolicy::requireTestEnvironment();
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,191}\z/D', $reference)) {
            throw new QuoteException('INVALID_QUOTE_REQUEST', 422);
        }
    }
}
