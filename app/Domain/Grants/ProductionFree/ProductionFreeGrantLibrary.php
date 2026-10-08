<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;

/** Current-owner library read side. A foreign or unknown origin is indistinguishable from an absent one. */
final class ProductionFreeGrantLibrary
{
    public const LIMIT = 50;

    public function __construct(private readonly int $limit = self::LIMIT)
    {
        ProductionFreeGrantException::require($limit >= 1 && $limit <= 1000, 'invalid_input');
    }

    public function index(ProductionCustomerPrincipal $principal, User $actor): array
    {
        $grants = new ProductionFreeGrants;

        return $grants->customerCommand($principal, $actor, function (array $policy, ProductionFreeGrantRows $rows, array $binding) use ($grants): array {
            $accountId = (int) $binding['account_id'];
            $total = $rows->count('production_free_origins', 'account_id = ?', [$accountId]);
            // The page is selected in SQL (newest first) before any limit, never as "first N by id, then sort".
            $ids = $rows->newest('production_free_origins', 'account_id = ?', [$accountId], $this->limit);
            $origins = $ids === [] ? [] : $rows->all('production_free_origins', 'id IN ('.implode(', ', array_fill(0, count($ids), '?')).')', $ids, $this->limit);
            usort($origins, fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);
            $items = array_map(fn (array $origin): array => $grants->project($grants->originGraph($origin['id'], $accountId, $rows)),
                array_slice($origins, 0, $this->limit));

            return ['schemaVersion' => 1, 'total' => $total, 'limit' => $this->limit, 'items' => $items];
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
