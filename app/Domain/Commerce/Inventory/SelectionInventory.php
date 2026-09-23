<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Catalog\Models\ExclusiveActivation;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use Illuminate\Support\Facades\DB;

/** Advisory browse/review availability. The reservation command performs the authoritative locked check. */
final class SelectionInventory
{
    public function available(int $revisionId, ?int $ownQuoteId = null): bool
    {
        $link = RightsScopeOffer::where('offer_revision_id', $revisionId)->first();
        $revision = OfferRevision::find($revisionId);
        if (! $revision) { return false; }
        // Once a track has explicitly linked into an activated scope, a new unlinked
        // successor cannot evade its cutoff. This only blocks; it never creates a link.
        $governed = $this->governedScopes($revision->track_id);
        if ($governed !== [] && (count($governed) !== 1 || ! $link || $link->rights_scope_id !== $governed[0])) { return false; }
        // Legacy inventory exercises retain their original behavior until an exclusive is explicitly activated.
        if (! $link || ! ExclusiveActivation::where('rights_scope_id', $link->rights_scope_id)->exists()) { return true; }
        if (RightsScope::whereKey($link->rights_scope_id)->where('blocked', true)->exists()) { return false; }

        return ! DB::table('inventory_reservations')->join('inventory_claims',
            'inventory_claims.inventory_reservation_id', '=', 'inventory_reservations.id')
            ->where('inventory_claims.rights_scope_id', $link->rights_scope_id)
            ->when($ownQuoteId !== null, fn ($query) => $query->where('quote_id', '<>', $ownQuoteId))
            ->where(fn ($query) => $query->where('state', 'pending')->orWhere(fn ($held) =>
                $held->where('state', 'held')->where('expires_at', '>', now())))
            ->exists();
    }

    public function governedScopes(int $trackId): array
    {
        return DB::table('rights_scope_offers as links')->join('offer_revisions as revisions', 'revisions.id', '=', 'links.offer_revision_id')
            ->where('revisions.track_id', $trackId)->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('exclusive_activations')->whereColumn('exclusive_activations.rights_scope_id', 'links.rights_scope_id'))
            ->distinct()->pluck('links.rights_scope_id')->map(fn ($id) => (int) $id)->all();
    }

    public function assertAvailable(array $lines, ?int $ownQuoteId = null): void
    {
        foreach ($lines as $line) {
            if (! $this->available($line['offer_revision_id'], $ownQuoteId)) {
                throw new QuoteException('INVENTORY_UNAVAILABLE', 409);
            }
        }
    }
}
