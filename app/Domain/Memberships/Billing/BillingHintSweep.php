<?php

namespace App\Domain\Memberships\Billing;

use App\Jobs\RetrieveMembershipInvoice;
use Illuminate\Support\Facades\DB;
use PDO;

/**
 * Operator reconciliation over retained `retrieval_hint` events (Addendum 1, A1-2). A webhook that was acknowledged and whose
 * retrieval later failed leaves a hint no provider redelivery will bring back; this finds the hints that no definitive observation
 * covers and, only when asked, queues one retrieval per uncovered invoice. It reads the ledger only: no provider call, no
 * observation row, no change to a retained event.
 */
final class BillingHintSweep
{
    public const MAX_HINTS = 1000;

    /**
     * `settled` counts the examined hints that need no retrieval (covered, unbound or not routable).
     *
     * @return array{examined: int, truncated: bool, pending: list<array{event_hash: string, type: string, received_at: string, invoice_hash: string, binding_id: string, invoice_ref: string}>, settled: int}
     */
    public function scan(int $limit, array $configuration): array
    {
        BillingException::require($limit >= 1 && $limit <= self::MAX_HINTS, 'technical_bound');
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table(BillingSchema::TABLES[3]).' WHERE disposition = ? ORDER BY received_at, id LIMIT '.($limit + 1));
        $statement->execute(['retrieval_hint']);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > $limit;
        $recovery = new BillingHintRecovery;
        $pending = [];
        $examined = 0;
        foreach (array_slice($rows, 0, $limit) as $row) {
            BillingException::require(hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
            $examined++;
            $need = $recovery->pending($row, $configuration);
            if ($need !== null) {
                $pending[] = ['event_hash' => $row['provider_event_ref_hash'], 'type' => $row['type'], 'received_at' => $row['received_at'],
                    'invoice_hash' => $row['invoice_ref_hash'], 'binding_id' => $need['binding_id'], 'invoice_ref' => $need['invoice_ref']];
            }
        }

        return ['examined' => $examined, 'truncated' => $truncated, 'pending' => $pending, 'settled' => $examined - count($pending)];
    }

    /**
     * Queues one retrieval per distinct uncovered invoice (several uncovered hints of one invoice need one retrieval that begins
     * after all of them). Returns how many were queued.
     *
     * @param  list<array{binding_id: string, invoice_hash: string, invoice_ref: string}>  $pending
     */
    public function dispatch(array $pending, array $configuration, BillingPolicy $policy): int
    {
        $seen = [];
        foreach ($pending as $hint) {
            if (isset($seen[$hint['invoice_hash']])) {
                continue;
            }
            $seen[$hint['invoice_hash']] = true;
            $policy->proveConfiguration($configuration);
            RetrieveMembershipInvoice::dispatch($hint['binding_id'], $hint['invoice_ref']);
        }

        return count($seen);
    }
}
