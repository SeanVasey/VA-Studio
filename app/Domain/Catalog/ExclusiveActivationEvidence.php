<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\ExclusiveActivation;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Inventory\ExclusiveSelectionPolicy;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;

final class ExclusiveActivationEvidence
{
    public function snapshot(OfferRevision $revision, array $policy, int $actor, string $at): array
    {
        return ['schema_version' => 1, 'purpose' => 'test_exclusive_activation',
            'offer_revision_id' => $revision->id, 'offer_snapshot_hash' => $revision->snapshot_hash,
            'inventory' => $revision->snapshot['inventory'], 'policy' => $policy,
            'activated_by' => $actor, 'created_at' => $at];
    }

    public function current(OfferRevision $revision): ExclusiveActivation
    {
        $policy = app(ExclusiveSelectionPolicy::class)->current();
        $activation = ExclusiveActivation::where('offer_revision_id', $revision->id)->first();
        if (! $activation || $activation->rights_scope_id !== ($revision->snapshot['inventory']['scope_id'] ?? null) ||
            $activation->canonicalization_version !== CanonicalJson::VERSION ||
            ! hash_equals($activation->snapshot_hash, CanonicalJson::hash($activation->snapshot)) ||
            ! hash_equals($activation->snapshot_hash, CanonicalJson::hash($this->snapshot($revision, $policy,
                $activation->activated_by, $activation->created_at->toIso8601ZuluString())))) {
            throw new QuoteException('SELECTION_CHANGED', 409);
        }

        return $activation;
    }
}
