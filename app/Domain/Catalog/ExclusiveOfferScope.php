<?php

namespace App\Domain\Catalog;

use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Throwable;

/** Private scope evidence for v2 test preparations; not a public license or ownership attestation. */
final class ExclusiveOfferScope
{
    public function capture(RightsScope $scope, string $reference): array
    {
        return [
            'scope_id' => $scope->id, 'scope_public_id' => $scope->public_id,
            'scope_identity_hash' => CanonicalJson::hash($scope->only(['public_id', 'scope_key', 'evidence_reference', 'created_by']) +
                ['created_at' => $scope->created_at->utc()->toIso8601ZuluString()]),
            'link_reference_hash' => hash('sha256', $reference),
        ];
    }

    public function blockers(OfferRevision $revision): array
    {
        try {
            InventoryPolicy::requireTestEnvironment();
            $snapshot = $revision->snapshot;
            $link = RightsScopeOffer::where('offer_revision_id', $revision->id)->first();
            $scope = $link ? RightsScope::find($link->rights_scope_id) : null;
            if (($snapshot['purpose'] ?? null) !== 'test_exclusive_preparation' || ! $scope || ! $link ||
                (int) $link->linked_by !== (int) $revision->published_by ||
                ! hash_equals(CanonicalJson::hash($snapshot['inventory'] ?? null), CanonicalJson::hash($this->capture($scope, $link->evidence_reference)))) {
                return ['The prepared exclusive revision has no matching immutable scope evidence.'];
            }

            return $scope->blocked ? ['The underlying rights scope is administratively blocked.'] : [];
        } catch (QueryException $exception) {
            throw $exception;
        } catch (Throwable) {
            return ['Exclusive revision preparation is available only in local/test environments with intact scope evidence.'];
        }
    }
}
