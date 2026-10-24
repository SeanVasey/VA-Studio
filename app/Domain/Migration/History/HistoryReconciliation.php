<?php

declare(strict_types=1);

namespace App\Domain\Migration\History;

use App\Support\CanonicalJson;

/**
 * Pure reconciliation of one history snapshot (expected) against what an archive actually holds (observed), per
 * runbook §5: for every cohort the expected, matched, missing and unexpected records; every stored original
 * contract re-hashed; money totals in minor units per currency compared from the stored columns; and every stored
 * record's encrypted copy proved against its record hash.
 *
 * Recorded source exceptions (a missing original, an ambiguous buyer, a duplicate exclusive sale) are listed as
 * explained: they are facts of the source, preserved as archive-only history. Every other difference is
 * unexplained, and the result is `reconciled` only when there are none.
 */
final class HistoryReconciliation
{
    public const PURPOSE = 'vasey-legacy-history-reconciliation-v1';

    private const COHORTS = ['customers', 'orders', 'lines', 'contracts'];

    /**
     * @param  array{records: array<string, array<string, array{record_sha256: string, total_minor?: int, refunded_minor?: int, currency?: string, artifact?: string}>>,
     *     unexpected: array<string, list<string>>, integrity_failures: list<array{cohort: string, record_key: string, code: string}>}  $observed
     *     records: cohort => record_key => the stored row matching the snapshot's (record_key, record_sha256), if any;
     *     unexpected: stored rows of this batch that the snapshot does not contain;
     *     integrity_failures: stored rows whose encrypted copy does not decrypt to their record hash
     */
    public function reconcile(array $snapshot, string $snapshotSha256, array $observed): array
    {
        $cohorts = [];
        $unexplained = 0;
        foreach (self::COHORTS as $cohort) {
            $expected = array_column($snapshot[$cohort], null, 'record_key');
            $stored = $observed['records'][$cohort] ?? [];
            $missing = [];
            foreach ($expected as $key => $record) {
                if (($stored[$key]['record_sha256'] ?? null) !== $record['record_sha256']) {
                    $missing[] = (string) $key;
                }
            }
            $unexpected = array_values($observed['unexpected'][$cohort] ?? []);
            sort($missing, SORT_STRING);
            sort($unexpected, SORT_STRING);
            $cohorts[$cohort] = ['expected' => count($expected), 'matched' => count($expected) - count($missing),
                'missing' => $missing, 'unexpected' => $unexpected];
            $unexplained += count($missing) + count($unexpected);
        }

        $artifacts = ['verified' => 0, 'missing' => [], 'hash_mismatch' => []];
        foreach ($snapshot['contracts'] as $contract) {
            $state = $observed['records']['contracts'][$contract['record_key']]['artifact'] ?? null;
            if ($state === 'verified') {
                $artifacts['verified']++;
            } elseif ($state === 'hash_mismatch') {
                $artifacts['hash_mismatch'][] = $contract['record_key'];
            } elseif ($state === 'missing') {
                $artifacts['missing'][] = $contract['record_key'];
            }
        }
        $unexplained += count($artifacts['missing']) + count($artifacts['hash_mismatch']);

        $money = [];
        foreach ($snapshot['orders'] as $order) {
            $currency = $order['currency'];
            $money[$currency] ??= ['expected_total_minor' => 0, 'actual_total_minor' => 0, 'expected_refunded_minor' => 0, 'actual_refunded_minor' => 0];
            $money[$currency]['expected_total_minor'] += $order['total_minor'];
            $money[$currency]['expected_refunded_minor'] += $order['refunded_minor'];
            $row = $observed['records']['orders'][$order['record_key']] ?? null;
            if ($row !== null && $row['record_sha256'] === $order['record_sha256'] && ($row['currency'] ?? null) === $currency) {
                $money[$currency]['actual_total_minor'] += $row['total_minor'] ?? 0;
                $money[$currency]['actual_refunded_minor'] += $row['refunded_minor'] ?? 0;
            }
        }
        foreach ($money as $currency => $totals) {
            $totals['total_delta_minor'] = $totals['actual_total_minor'] - $totals['expected_total_minor'];
            $totals['refunded_delta_minor'] = $totals['actual_refunded_minor'] - $totals['expected_refunded_minor'];
            // Missing orders are already counted; a stored column that disagrees with its record is a separate difference.
            $unexplained += ($totals['total_delta_minor'] !== 0 || $totals['refunded_delta_minor'] !== 0) && $cohorts['orders']['missing'] === [] ? 1 : 0;
            ksort($totals, SORT_STRING);
            $money[$currency] = $totals;
        }
        ksort($money, SORT_STRING);

        $integrity = $observed['integrity_failures'] ?? [];
        usort($integrity, static fn (array $a, array $b): int => [$a['cohort'], $a['record_key'], $a['code']] <=> [$b['cohort'], $b['record_key'], $b['code']]);
        $unexplained += count($integrity);

        $explained = [];
        foreach ($snapshot['exceptions'] as $exception) {
            $explained[$exception['code']] = ($explained[$exception['code']] ?? 0) + 1;
        }
        ksort($explained, SORT_STRING);

        $report = ['purpose' => self::PURPOSE, 'snapshot_sha256' => $snapshotSha256, 'source_system' => $snapshot['source_system'],
            'snapshot_id' => $snapshot['snapshot_id'], 'cohorts' => $cohorts, 'artifacts' => $artifacts, 'money' => $money,
            'integrity_failures' => $integrity, 'explained_exceptions' => $explained,
            'eligible_for_review' => count(array_filter($snapshot['eligibility'], static fn (array $line): bool => $line['eligible'])),
            'unexplained' => $unexplained, 'result' => $unexplained === 0 ? 'reconciled' : 'unreconciled'];
        $report['reconciliation_sha256'] = CanonicalJson::hash($report);

        return $report;
    }
}
