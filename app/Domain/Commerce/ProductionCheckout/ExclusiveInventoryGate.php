<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Migration\History\ExclusivityAuthority;
use Throwable;

/**
 * Exclusive and scoped inventory in production checkout (lane PCC; MIG decision C5). The chain sells non-exclusive v1 inventory
 * only, so such a selection is always refused. The exclusivity authority (ExclusivityAuthority::storeMaySellExclusives(), the
 * BeatStars cutover ledger) is consulted first: while the store is not the single exclusive authority (an empty ledger,
 * `beatstars` or `frozen`) the refusal is `exclusivity_authority`, and any failure to read the ledger counts as "no". When the
 * store is the authority the refusal is still `unsupported_inventory`, because no production exclusive consumer exists; a future
 * one inherits the authority check by replacing only that final refusal.
 *
 * Detection follows PreparationSelection::interpret's own composition rule (an active exclusive offer refuses the whole track):
 * any rights-scope link on the tracks' revisions, or an active offer whose current revision is an exclusive snapshot or carries
 * an exclusive license template. Anything else malformed is left to the existing proofs, which refuse it.
 */
final class ExclusiveInventoryGate
{
    public static function refuse(array $graph, array $links): void
    {
        if (! self::exclusive($graph, $links)) {
            return;
        }
        CheckoutException::require(self::storeMaySellExclusives(), 'exclusivity_authority');

        throw new CheckoutException('unsupported_inventory');
    }

    private static function storeMaySellExclusives(): bool
    {
        try {
            return app(ExclusivityAuthority::class)->storeMaySellExclusives() === true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function exclusive(array $graph, array $links): bool
    {
        if ($links !== []) {
            return true;
        }
        $revisions = array_column($graph['revisions'] ?? [], null, 'id');
        $licenses = array_column($graph['licenses'] ?? [], null, 'id');
        $templates = array_column($graph['templates'] ?? [], null, 'id');
        foreach ($graph['offers'] ?? [] as $offer) {
            if ((int) ($offer['is_active'] ?? 0) !== 1) {
                continue;
            }
            $revision = $revisions[$offer['current_revision_id'] ?? 0] ?? null;
            if (! is_array($revision)) {
                continue;
            }
            $snapshot = is_string($revision['snapshot'] ?? null) ? json_decode($revision['snapshot'], true, 24) : null;
            if (is_array($snapshot) && (($snapshot['commercial']['type'] ?? null) === 'exclusive'
                || ($snapshot['purpose'] ?? null) === 'test_exclusive_preparation')) {
                return true;
            }
            $license = $licenses[$revision['license_version_id'] ?? 0] ?? null;
            $template = is_array($license) ? ($templates[$license['license_template_id'] ?? 0] ?? null) : null;
            if (is_array($template) && ($template['type'] ?? null) === 'exclusive') {
                return true;
            }
        }

        return false;
    }
}
