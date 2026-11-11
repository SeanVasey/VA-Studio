<?php

namespace App\Domain\Commerce\Inventory;

use App\Domain\Catalog\Models\ExclusiveActivation;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Models\ExclusiveSale;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Support\PublicReadMemo;
use Carbon\CarbonInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Advisory browse/review availability. The reservation command performs the authoritative locked check. */
final class SelectionInventory
{
    public function available(int $revisionId, ?int $ownQuoteId = null, ?CarbonInterface $at = null): bool
    {
        $link = PublicReadMemo::remember('rights-scope-link:'.$revisionId, fn () => RightsScopeOffer::where('offer_revision_id', $revisionId)->first());
        // Within a public read decision (PublicReadMemo) these are read once per revision and track; reservation-time and
        // transactional callers always read them directly.
        $revision = PublicReadMemo::remember('offer-revision:'.$revisionId, fn () => OfferRevision::find($revisionId));
        if (! $revision) {
            return false;
        }
        // Once a track has explicitly linked into an activated or sold scope, a new unlinked
        // successor cannot evade its cutoff. This only blocks; it never creates a link.
        $governed = PublicReadMemo::remember('governed-scopes:'.$revision->track_id, fn (): array => $this->governedScopes($revision->track_id));
        if ($governed !== [] && (count($governed) !== 1 || ! $link || $link->rights_scope_id !== $governed[0])) {
            return false;
        }
        // A sale has no own-quote exemption and applies to every explicitly linked
        // variant, including legacy offers without their own exclusive activation.
        if ($link && ExclusiveSale::where('rights_scope_id', $link->rights_scope_id)->exists()) {
            return false;
        }
        // Legacy inventory exercises retain their original behavior until an exclusive is explicitly activated.
        if (! $link || ! ExclusiveActivation::where('rights_scope_id', $link->rights_scope_id)->exists()) {
            return true;
        }
        if (RightsScope::whereKey($link->rights_scope_id)->where('blocked', true)->exists()) {
            return false;
        }

        return ! DB::table('inventory_reservations')->join('inventory_claims',
            'inventory_claims.inventory_reservation_id', '=', 'inventory_reservations.id')
            ->where('inventory_claims.rights_scope_id', $link->rights_scope_id)
            ->when($ownQuoteId !== null, fn ($query) => $query->where('quote_id', '<>', $ownQuoteId))
            ->where(fn ($query) => $query->where('state', 'pending')->orWhere(fn ($held) => $held->where('state', 'held')->where('expires_at', '>', $at ?? now())))
            ->exists();
    }

    public function governedScopes(int $trackId): array
    {
        return $this->governed([$trackId])->pluck('links.rights_scope_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * governedScopes() for several tracks in one read (public listing prefetch): track ID => that track's governed scope IDs.
     *
     * @param  list<int>  $trackIds
     * @return array<int, list<int>>
     */
    public function governedScopesByTrack(array $trackIds): array
    {
        $scopes = array_fill_keys($trackIds, []);
        foreach ($this->governed($trackIds)->get(['links.rights_scope_id', 'revisions.track_id']) as $row) {
            $scopes[(int) $row->track_id][] = (int) $row->rights_scope_id;
        }

        return $scopes;
    }

    private function governed(array $trackIds): Builder
    {
        return DB::table('rights_scope_offers as links')->join('offer_revisions as revisions', 'revisions.id', '=', 'links.offer_revision_id')
            ->whereIn('revisions.track_id', $trackIds)->where(fn ($scope) => $scope
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('exclusive_activations')
                ->whereColumn('exclusive_activations.rights_scope_id', 'links.rights_scope_id'))
            ->orWhereExists(fn ($query) => $query->selectRaw('1')->from('exclusive_sales')
                ->whereColumn('exclusive_sales.rights_scope_id', 'links.rights_scope_id')))
            ->distinct();
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
