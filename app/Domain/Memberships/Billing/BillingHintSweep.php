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
 *
 * Hints are retained forever, so one page of the oldest hints cannot reach newer ones (Codex P1 on PR #54, review L2-2). The scan
 * is a keyset over `(received_at, id)`: each page that stops short of the end returns a cursor that continues after its last
 * examined hint, and scanAll() follows those cursors until the hints are exhausted or a hard overall bound is reached, which it
 * reports with the cursor that continues it. A cursor carries only a hint's receipt time and internal row id, and an HMAC under the
 * application key bound to the configured provider account and mode, so an edited, truncated or foreign cursor is refused.
 */
final class BillingHintSweep
{
    /** Hints examined by one page. */
    public const MAX_HINTS = 1000;

    /** Hints examined by one scanAll(), across all its pages. */
    public const MAX_SWEEP_HINTS = 10000;

    private const CURSOR = '/\Av1\.([0-9]{14})\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\.([0-9a-f]{64})\z/D';

    public function __construct(private readonly int $sweepBound = self::MAX_SWEEP_HINTS)
    {
        BillingException::require($sweepBound >= 1 && $sweepBound <= self::MAX_SWEEP_HINTS, 'technical_bound');
    }

    /**
     * One page of at most `$limit` hints after `$after` (a cursor from an earlier page; null starts at the oldest hint). `settled`
     * counts the examined hints that need no retrieval (covered, unbound or not routable). `next` is the cursor that continues after
     * the last examined hint, or null when no hint follows it.
     *
     * @return array{examined: int, truncated: bool, next: ?string, pending: list<array{event_hash: string, type: string, received_at: string, invoice_hash: string, binding_id: string, invoice_ref: string}>, settled: int}
     */
    public function scan(int $limit, array $configuration, ?string $after = null): array
    {
        BillingException::require($limit >= 1 && $limit <= self::MAX_HINTS, 'technical_bound');
        // The cursor is proven before the first ledger read.
        $from = $after === null ? null : $this->openCursor($after, $configuration);
        $pdo = DB::connection()->getPdo();
        (new BillingSchema)->assertOwned($pdo);
        $where = 'disposition = ?';
        $bindings = ['retrieval_hint'];
        if ($from !== null) {
            $where .= ' AND (received_at > ? OR (received_at = ? AND id > ?))';
            array_push($bindings, $from['received_at'], $from['received_at'], $from['id']);
        }
        $statement = $pdo->prepare('SELECT * FROM '.(new BillingSchema)->table(BillingSchema::TABLES[3]).' WHERE '.$where.' ORDER BY received_at, id LIMIT '.($limit + 1));
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > $limit;
        $recovery = new BillingHintRecovery;
        $pending = [];
        $examined = 0;
        $last = null;
        foreach (array_slice($rows, 0, $limit) as $row) {
            $row = BillingValues::integers($row, ['hint_position']);
            BillingException::require(hash_equals(BillingValues::seal($row), $row['seal']), 'tampered_ledger');
            $examined++;
            $last = $row;
            $need = $recovery->pending($row, $configuration);
            if ($need !== null) {
                $pending[] = ['event_hash' => $row['provider_event_ref_hash'], 'type' => $row['type'], 'received_at' => $row['received_at'],
                    'invoice_hash' => $row['invoice_ref_hash'], 'binding_id' => $need['binding_id'], 'invoice_ref' => $need['invoice_ref']];
            }
        }

        return ['examined' => $examined, 'truncated' => $truncated, 'next' => $truncated && $last !== null ? $this->cursor($last, $configuration) : null,
            'pending' => $pending, 'settled' => $examined - count($pending)];
    }

    /**
     * Pages of `$limit` hints from `$after` until no hint follows or `sweepBound` hints were examined. `truncated` then means the
     * bound stopped the scan and `next` continues it. Every page re-checks seals and coverage exactly as scan() does.
     *
     * @return array{examined: int, pages: int, truncated: bool, next: ?string, pending: list<array{event_hash: string, type: string, received_at: string, invoice_hash: string, binding_id: string, invoice_ref: string}>, settled: int}
     */
    public function scanAll(int $limit, array $configuration, ?string $after = null): array
    {
        BillingException::require($limit >= 1 && $limit <= self::MAX_HINTS, 'technical_bound');
        $examined = $settled = $pages = 0;
        $pending = [];
        $cursor = $after;
        do {
            $page = $this->scan(min($limit, $this->sweepBound - $examined), $configuration, $cursor);
            $pages++;
            $examined += $page['examined'];
            $settled += $page['settled'];
            array_push($pending, ...$page['pending']);
            $cursor = $page['next'];
        } while ($cursor !== null && $examined < $this->sweepBound);

        return ['examined' => $examined, 'pages' => $pages, 'truncated' => $cursor !== null, 'next' => $cursor, 'pending' => $pending, 'settled' => $settled];
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

    /** `v1.<received_at as YYYYMMDDHHMMSS>.<hint row id>.<HMAC-SHA256>`: no provider reference, hash or secret. */
    private function cursor(array $row, array $configuration): string
    {
        BillingException::require(preg_match('/\A[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\z/D', (string) $row['received_at']) === 1
            && preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', (string) $row['id']) === 1, 'tampered_ledger');
        $stamp = str_replace(['-', ' ', ':'], '', $row['received_at']);

        return 'v1.'.$stamp.'.'.$row['id'].'.'.$this->mac($stamp, $row['id'], $configuration);
    }

    /** @return array{received_at: string, id: string} */
    private function openCursor(string $cursor, array $configuration): array
    {
        BillingException::require(strlen($cursor) <= 200 && preg_match(self::CURSOR, $cursor, $parts) === 1, 'cursor');
        BillingException::require(hash_equals($this->mac($parts[1], $parts[2], $configuration), $parts[3]), 'cursor');
        $s = $parts[1];

        return ['received_at' => substr($s, 0, 4).'-'.substr($s, 4, 2).'-'.substr($s, 6, 2).' '.substr($s, 8, 2).':'.substr($s, 10, 2).':'.substr($s, 12, 2),
            'id' => $parts[2]];
    }

    private function mac(string $stamp, string $id, array $configuration): string
    {
        $key = (string) config('app.key');
        BillingException::require($key !== '', 'cursor');

        return hash_hmac('sha256', implode("\0", ['production-membership-billing-sweep-cursor-v1', $configuration['account_ref'], $configuration['mode'], $stamp, $id]), $key);
    }
}
