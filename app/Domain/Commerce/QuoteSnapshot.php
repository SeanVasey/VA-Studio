<?php

namespace App\Domain\Commerce;

use App\Domain\Commerce\Inventory\ExclusiveSelectionPolicy;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Models\RightsScopeOffer;

/** V1 remains byte-compatible. V2 adds explicit test activation, policy and exact scope bindings. */
final class QuoteSnapshot
{
    public function capture(string $id, $issued, $expires, array $lines): array
    {
        $exclusive = collect($lines)->contains(fn ($line) => ($line['offer_snapshot']['commercial']['type'] ?? null) === 'exclusive');
        $snapshot = ['schema_version' => $exclusive ? 2 : 1, 'purpose' => 'selection_review', 'payable' => false,
            'public_id' => $id, 'issued_at' => $issued->utc()->toIso8601ZuluString(), 'expires_at' => $expires->utc()->toIso8601ZuluString(),
            'currency' => 'USD', 'subtotal_minor' => array_sum(array_column(array_column(array_column($lines, 'offer_snapshot'), 'commercial'), 'price_minor')),
            'tax_status' => 'unresolved', 'tax_minor' => null, 'total_minor' => null, 'lines' => $lines];
        if ($exclusive) {
            $snapshot['selection_policy'] = app(ExclusiveSelectionPolicy::class)->current();
            $snapshot['inventory_policy'] = app(InventoryPolicy::class)->current();
            $links = RightsScopeOffer::whereIn('offer_revision_id', array_column($lines, 'offer_revision_id'))->orderBy('rights_scope_id')->get();
            if ($links->count() !== count($lines) || $links->pluck('rights_scope_id')->unique()->count() !== count($lines)) {
                throw new QuoteException('INVENTORY_SCOPE_UNAVAILABLE', 409);
            }
            $snapshot['scope_bindings'] = $links->map(fn ($link) => ['scope_id' => $link->rights_scope_id,
                'link_id' => $link->id, 'offer_revision_id' => $link->offer_revision_id])->all();
        }

        return $snapshot;
    }
}
