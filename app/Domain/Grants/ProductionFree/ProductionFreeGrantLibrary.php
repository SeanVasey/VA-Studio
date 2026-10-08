<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;

/** Current-owner library read side. A foreign or unknown origin is indistinguishable from an absent one. */
final class ProductionFreeGrantLibrary
{
    public const LIMIT = 50;

    public function index(ProductionCustomerPrincipal $principal, User $actor): array
    {
        $grants = new ProductionFreeGrants;

        return $grants->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($grants): array {
            $accountId = (int) $binding['account_id'];
            $total = $rows->count('production_free_origins', 'account_id = ?', [$accountId]);
            $origins = $rows->all('production_free_origins', 'account_id = ?', [$accountId], 1000);
            usort($origins, fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
            $items = array_map(fn (array $origin): array => $grants->project($grants->originGraph($origin['id'], $accountId, $rows)),
                array_slice($origins, 0, self::LIMIT));

            return ['schemaVersion' => 1, 'total' => $total, 'limit' => self::LIMIT, 'items' => $items];
        });
    }

    /** Detail adds the exact frozen terms and assent the owner affirmed, plus the original seal for authorizing. */
    public function show(string $originId, ProductionCustomerPrincipal $principal, User $actor): array
    {
        ProductionFreeGrantInput::uuid($originId);
        $grants = new ProductionFreeGrants;

        return $grants->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($grants, $originId): array {
            $graph = $grants->originGraph($originId, (int) $binding['account_id'], $rows);
            $p = $graph['payload'];

            return [...$grants->project($graph), 'originSeal' => $graph['origin']['seal'], 'termsText' => $p['definition']['terms_text'],
                'assentText' => $p['definition']['assent_text'], 'displayHash' => $p['display_hash'], 'marketingConsent' => 'unknown'];
        });
    }
}
