<?php

namespace Tests\Unit\Migration;

use App\Domain\Migration\History\HistoryDelta;
use App\Domain\Migration\History\HistoryNormalizer;
use App\Domain\Migration\History\HistoryReconciliation;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Pure delta capture and reconciliation arithmetic on the synthetic baseline and later packages. */
class HistoryDeltaTest extends TestCase
{
    public function test_baseline_to_later_captures_the_new_sale_the_refund_and_the_new_exclusive_with_nothing_unexplained(): void
    {
        [$baseline, $later] = $this->snapshots();
        $report = (new HistoryDelta)->compare($baseline, $later);
        $this->assertSame((new HistoryDelta)->compare($baseline, $later), $report);
        $this->assertSame('reconciled', $report['result']);
        $this->assertSame(0, $report['unexplained']);
        $this->assertSame(0, $report['blocking']);
        $this->assertSame(['baseline' => 4, 'later' => 5, 'new' => 1, 'changed' => 0, 'removed' => 0, 'unchanged' => 4], $report['counts']['customers']);
        $this->assertSame(['baseline' => 4, 'later' => 5, 'new' => 1, 'changed' => 1, 'removed' => 0, 'unchanged' => 3], $report['counts']['orders']);
        $this->assertSame(['baseline' => 5, 'later' => 6, 'new' => 1, 'changed' => 2, 'removed' => 0, 'unchanged' => 3], $report['counts']['lines']);
        $this->assertSame(['baseline' => 4, 'later' => 5, 'new' => 1, 'changed' => 0, 'removed' => 0, 'unchanged' => 4], $report['counts']['contracts']);
        $categories = array_count_values(array_column($report['items'], 'category'));
        ksort($categories);
        $this->assertSame(['new_record' => 4, 'refund' => 2, 'source_row_restated' => 1], $categories);
        $newExclusive = $this->line($later, 'synthetic-ord-1005', '1');
        $this->assertSame(['new' => [$newExclusive], 'changed' => [], 'removed' => []], $report['exclusive_sales']);
        $this->assertSame([['record_key' => $this->line($later, 'synthetic-ord-1001', '2'), 'refunded_minor_delta' => 4999]], $report['refunds']);
        $this->assertSame(['baseline_refunded_minor' => 2999, 'baseline_total_minor' => 2999 + 4999 + 50000 + 2999 + 2999,
            'later_refunded_minor' => 2999 + 4999, 'later_total_minor' => 2999 + 4999 + 50000 + 2999 + 2999 + 75000,
            'refunded_delta_minor' => 4999, 'total_delta_minor' => 75000], $report['money']['USD']);
        $this->assertSame(CanonicalJson::hash($baseline), $report['baseline']['snapshot_sha256']);
        $this->assertStringNotContainsString('@', CanonicalJson::encode($report), 'The delta report carries no e-mail address.');
    }

    public function test_a_removed_record_blocks_until_an_owner_disposition_bound_to_both_snapshots_explains_it(): void
    {
        [$baseline, $later] = $this->snapshots(function (array $source): array {
            $source['sheets']['orders'] = preg_replace('/^synthetic-ord-1003,.*\n/m', '', $source['sheets']['orders']);
            $source['sheets']['contracts'] = preg_replace('/^synthetic-contract-1003-1,.*\n/m', '', $source['sheets']['contracts']);
            unset($source['contracts']['synthetic-contract-1003-1.txt']);

            return $source;
        });
        $report = (new HistoryDelta)->compare($baseline, $later);
        $this->assertSame('unreconciled', $report['result']);
        $removed = array_values(array_filter($report['items'], static fn (array $item): bool => $item['change'] === 'removed'));
        $this->assertSame(['contract', 'line', 'order'], array_column($removed, 'cohort'));
        $this->assertSame(3, $report['unexplained']);

        $items = [];
        foreach ($removed as $item) {
            $items[$item['cohort'].':'.$item['record_key']] = ['disposition' => 'retained_prior_record', 'reason' => 'SYNTHETIC owner confirmed refunded order purged at source'];
        }
        $dispositions = ['schema_version' => 1, 'purpose' => HistoryDelta::DISPOSITIONS_PURPOSE,
            'baseline_snapshot_sha256' => $report['baseline']['snapshot_sha256'], 'later_snapshot_sha256' => $report['later']['snapshot_sha256'], 'items' => $items];
        $explained = (new HistoryDelta)->compare($baseline, $later, json_encode($dispositions));
        $this->assertSame('reconciled', $explained['result']);
        $this->assertSame(3, $explained['dispositions']);

        foreach ([
            'dispositions_mismatch' => ['later_snapshot_sha256' => str_repeat('0', 64)] + $dispositions,
            'dispositions_unknown_item' => ['items' => $items + ['customer:'.str_repeat('a', 64) => ['disposition' => 'retained_prior_record', 'reason' => 'stale']]] + $dispositions,
            'dispositions_invalid' => ['items' => array_map(static fn (array $entry): array => ['disposition' => 'ignore'] + $entry, $items)] + $dispositions,
        ] as $code => $bad) {
            try {
                (new HistoryDelta)->compare($baseline, $later, json_encode($bad));
                $this->fail($code.' accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame($code, $error->getMessage());
            }
        }
    }

    public function test_changed_money_terms_exclusives_and_contract_bytes_are_blocking(): void
    {
        [$baseline, $later] = $this->snapshots(function (array $source): array {
            $source['sheets']['orders'] = str_replace('Exclusive Rights,500,0,synthetic-pay-1002', 'Exclusive Rights,450,0,synthetic-pay-1002', $source['sheets']['orders']);
            $source['sheets']['orders'] = str_replace('synthetic-track-04,Basic Lease,29.99', 'synthetic-track-04,Premium Lease,29.99', $source['sheets']['orders']);
            $source['contracts']['synthetic-contract-1001-1.txt'] .= "SYNTHETIC re-rendered copy\n";
            $source['sheets']['contracts'] = preg_replace('/(synthetic-contract-1001-1\.txt,)[a-f0-9]{64}/', '$1'.hash('sha256', $source['contracts']['synthetic-contract-1001-1.txt']), $source['sheets']['contracts']);

            return $source;
        });
        $report = (new HistoryDelta)->compare($baseline, $later);
        $blocking = [];
        foreach ($report['items'] as $item) {
            if ($item['blocking']) {
                $blocking[] = $item['cohort'].':'.$item['category'];
            }
        }
        sort($blocking);
        $this->assertSame(['contract:contract_original_changed', 'line:exclusive_line_changed', 'line:line_terms_changed',
            'order:order_terms_changed'], $blocking);
        $this->assertSame('unreconciled', $report['result']);
        $this->assertContains($this->line($later, 'synthetic-ord-1002', '1'), $report['exclusive_sales']['changed']);
    }

    public function test_the_later_watermark_must_follow_the_baseline_and_the_source_system_must_match(): void
    {
        [$baseline, $later] = $this->snapshots();
        foreach (['delta_watermark_order' => [$later, $baseline], 'delta_source_system' => [$baseline, ['source_system' => 'other'] + $later]] as $code => [$a, $b]) {
            try {
                (new HistoryDelta)->compare($a, $b);
                $this->fail($code.' accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame($code, $error->getMessage());
            }
        }
    }

    public function test_reconciliation_passes_only_with_zero_unexplained_differences(): void
    {
        [$snapshot] = $this->snapshots();
        $sha = CanonicalJson::hash($snapshot);
        $observed = $this->perfect($snapshot);
        $report = (new HistoryReconciliation)->reconcile($snapshot, $sha, $observed);
        $this->assertSame('reconciled', $report['result']);
        $this->assertEquals(['contract_original_missing' => 1, 'ambiguous_customer_identity' => 2], $report['explained_exceptions']);
        $this->assertSame(3, $report['eligible_for_review']);
        $this->assertEquals(['expected_refunded_minor' => 2999, 'expected_total_minor' => 63996, 'actual_refunded_minor' => 2999, 'actual_total_minor' => 63996,
            'refunded_delta_minor' => 0, 'total_delta_minor' => 0], $report['money']['USD']);

        $order = $snapshot['orders'][0]['record_key'];
        $contract = $snapshot['contracts'][0]['record_key'];
        foreach ([
            'missing record' => function (array $o) use ($snapshot): array {
                unset($o['records']['lines'][$snapshot['lines'][0]['record_key']]);

                return $o;
            },
            'changed record hash' => function (array $o) use ($snapshot): array {
                $o['records']['customers'][$snapshot['customers'][0]['record_key']]['record_sha256'] = str_repeat('0', 64);

                return $o;
            },
            'unexpected stored row' => fn (array $o): array => ['unexpected' => ['customers' => [str_repeat('b', 64)]]] + $o,
            'altered stored row' => fn (array $o): array => ['integrity_failures' => [['cohort' => 'orders', 'record_key' => $order, 'code' => 'stored_record_altered']]] + $o,
            'tampered original' => function (array $o) use ($contract): array {
                $o['records']['contracts'][$contract]['artifact'] = 'hash_mismatch';

                return $o;
            },
            'missing original' => function (array $o) use ($contract): array {
                $o['records']['contracts'][$contract]['artifact'] = 'missing';

                return $o;
            },
            'stored money column disagrees' => function (array $o) use ($order): array {
                $o['records']['orders'][$order]['total_minor']++;

                return $o;
            },
        ] as $case => $change) {
            $failed = (new HistoryReconciliation)->reconcile($snapshot, $sha, $change($observed));
            $this->assertSame('unreconciled', $failed['result'], $case);
            $this->assertGreaterThan(0, $failed['unexplained'], $case);
        }
    }

    private function perfect(array $snapshot): array
    {
        $records = [];
        foreach (['customers', 'orders', 'lines', 'contracts'] as $cohort) {
            foreach ($snapshot[$cohort] as $record) {
                $records[$cohort][$record['record_key']] = ['record_sha256' => $record['record_sha256']]
                    + ($cohort === 'orders' ? ['total_minor' => $record['total_minor'], 'refunded_minor' => $record['refunded_minor'], 'currency' => $record['currency']] : [])
                    + ($cohort === 'contracts' ? ['artifact' => 'verified'] : []);
            }
        }

        return ['records' => $records, 'unexpected' => [], 'integrity_failures' => []];
    }

    private function line(array $snapshot, string $order, string $line): string
    {
        foreach ($snapshot['lines'] as $record) {
            if ($record['source_order_id'] === $order && $record['source_line_id'] === $line) {
                return $record['record_key'];
            }
        }
        $this->fail('Line not found.');
    }

    /** @return array{0: array, 1: array} */
    private function snapshots(?callable $changeLater = null): array
    {
        $baseline = (new HistoryNormalizer)->normalize(HistoryNormalizerTest::fixture())['snapshot'];
        $source = HistoryNormalizerTest::fixture('later');
        $result = (new HistoryNormalizer)->normalize($changeLater === null ? $source : $changeLater($source));
        $this->assertSame([], $result['findings']);

        return [$baseline, $result['snapshot']];
    }
}
