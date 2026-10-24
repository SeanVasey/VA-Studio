<?php

namespace Tests\Unit\Migration;

use App\Domain\Migration\History\HistoryMapping;
use App\Domain\Migration\History\HistoryNormalizer;
use App\Domain\Migration\History\HistorySourceFiles;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pure history normalizer behaviour on the synthetic fixture and programmatic variants; no database or writes. */
class HistoryNormalizerTest extends TestCase
{
    public static function fixture(string $package = 'baseline'): array
    {
        return (new HistorySourceFiles)->read(dirname(__DIR__, 2).'/Fixtures/migration/history-synthetic/'.$package);
    }

    public function test_synthetic_baseline_normalizes_deterministically_with_preserved_identities_and_unknown_consent(): void
    {
        $source = self::fixture();
        $this->assertTrue($source['synthetic_repository_fixture']);
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertSame($result, (new HistoryNormalizer)->normalize(self::fixture()));
        $this->assertSame([], $result['findings']);
        $this->assertSame(['customers' => 4, 'orders' => 4, 'lines' => 5, 'contracts' => 4, 'contract_files' => 4, 'findings' => 0, 'exceptions' => 3], $result['counts']);
        $snapshot = $result['snapshot'];
        $this->assertSame(CanonicalJson::hash($snapshot), $result['snapshot_sha256']);
        $this->assertSame(HistoryNormalizer::SCHEMA, $snapshot['schema']);
        $this->assertSame('synthetic_fixture', $snapshot['acquisition_method']);
        $this->assertSame(hash('sha256', $source['mapping']), $snapshot['inputs']['mapping_sha256']);
        foreach (HistorySourceFiles::SHEETS as $sheet => $name) {
            $this->assertSame(hash('sha256', $source['sheets'][$sheet]), $snapshot['inputs']['sheets'][$sheet]['sha256']);
        }
        $this->assertSame(['synthetic-cust-001', 'synthetic-cust-002', 'synthetic-cust-003', 'synthetic-cust-004'],
            $this->sorted(array_column($snapshot['customers'], 'source_customer_id')));
        // Consent is never inferred: every customer is unknown, the opt-in column is ignored and its values are not retained.
        $this->assertSame(['unknown'], array_values(array_unique(array_column($snapshot['customers'], 'marketing_consent'))));
        $encoded = CanonicalJson::encode($snapshot);
        $this->assertStringNotContainsString('Marketing', $encoded);
        $this->assertStringNotContainsString('"yes"', $encoded);
        $this->assertSame(['archive_only'], array_values(array_unique(array_column($snapshot['lines'], 'classification'))));
        foreach (['customers', 'orders', 'lines', 'contracts'] as $cohort) {
            foreach ($snapshot[$cohort] as $record) {
                $this->assertSame(HistoryNormalizer::recordHash($record), $record['record_sha256']);
                $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $record['record_key']);
            }
        }
        $lines = array_column($snapshot['lines'], null, 'source_order_id');
        $this->assertSame(500 * 100, $lines['synthetic-ord-1002']['amount_minor']);
        $this->assertTrue($lines['synthetic-ord-1002']['exclusive']);
        $orders = array_column($snapshot['orders'], null, 'source_order_id');
        $this->assertSame(2999 + 4999, $orders['synthetic-ord-1001']['total_minor']);
        $this->assertSame('paid', $orders['synthetic-ord-1001']['status']);
        $this->assertSame(2999, $orders['synthetic-ord-1003']['refunded_minor']);
        $this->assertNull($orders['synthetic-ord-1004']['payment_reference']);
        // Each contract keeps the exact original's hash and size; nothing is rendered.
        foreach ($snapshot['contracts'] as $contract) {
            $bytes = $source['contracts'][$contract['file_name']];
            $this->assertSame(hash('sha256', $bytes), $contract['sha256']);
            $this->assertSame(strlen($bytes), $contract['bytes']);
            $this->assertSame('text/plain', $contract['media_type']);
        }
        $this->assertSame(['ambiguous_customer_identity' => 2, 'contract_original_missing' => 1], $this->codes($snapshot['exceptions']));
        $eligibility = $snapshot['eligibility'];
        $byOrder = [];
        foreach ($snapshot['lines'] as $line) {
            $byOrder[$line['source_order_id'].'/'.$line['source_line_id']] = $eligibility[$line['record_key']]['blockers'];
        }
        ksort($byOrder);
        $this->assertSame([
            'synthetic-ord-1001/1' => [], 'synthetic-ord-1001/2' => [], 'synthetic-ord-1002/1' => [],
            'synthetic-ord-1003/1' => ['ambiguous_customer_identity', 'line_refunded', 'order_not_paid'],
            'synthetic-ord-1004/1' => ['ambiguous_customer_identity', 'contract_original_missing', 'payment_reference_missing'],
        ], $byOrder);
    }

    public function test_record_keys_are_stable_across_snapshots_and_hashes_track_content(): void
    {
        $baseline = (new HistoryNormalizer)->normalize(self::fixture())['snapshot'];
        $later = (new HistoryNormalizer)->normalize(self::fixture('later'))['snapshot'];
        $before = array_column($baseline['customers'], 'record_sha256', 'record_key');
        $after = array_column($later['customers'], 'record_sha256', 'record_key');
        $this->assertSame($before, array_intersect_key($after, $before));
        $this->assertCount(1, array_diff_key($after, $before));
    }

    public function test_a_consent_column_must_be_ignored_and_cannot_be_mapped(): void
    {
        $source = self::fixture();
        $mapping = json_decode($source['mapping'], true);
        unset($mapping['customers']['ignored_columns']);
        $source['mapping'] = json_encode($mapping);
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertNull($result['snapshot']);
        $this->assertSame([['scope' => 'sheet', 'sheet' => 'customers', 'row' => null, 'source_id_sha256' => null, 'code' => 'unmapped_column', 'column' => 'Marketing Opt-In']], $result['findings']);

        $mapping['customers']['columns']['marketing_consent'] = 'Marketing Opt-In';
        $this->expectExceptionObject(new InvalidArgumentException('mapping_columns'));
        (new HistoryMapping)->decode(json_encode($mapping));
    }

    public static function findings(): array
    {
        $sha = hash('sha256', 'tampered');

        return [
            'duplicate customer id' => ['customers', 'synthetic-cust-002,buyer.two@', 'synthetic-cust-001,buyer.two@', 'duplicate_source_id'],
            'invalid email' => ['customers', 'buyer.two@example.invalid', 'buyer.two-at-example.invalid', 'invalid_email'],
            'non-synthetic email in a synthetic fixture' => ['customers', 'buyer.two@example.invalid', 'buyer.two@example.com', 'synthetic_identity_required'],
            'padded customer id' => ['customers', 'synthetic-cust-002,', ' synthetic-cust-002,', 'invalid_source_id'],
            'duplicate line' => ['orders', 'synthetic-ord-1001,2,', 'synthetic-ord-1001,1,', 'duplicate_source_id'],
            'unknown status' => ['orders', '12:30:00Z,Completed', '12:30:00Z,Settled', 'unknown_status_value'],
            'unknown license' => ['orders', 'Exclusive Rights,500', 'Exclusive Rights Plus,500', 'unknown_license_name'],
            'invalid amount' => ['orders', 'Exclusive Rights,500,', 'Exclusive Rights,"1,500",', 'invalid_amount'],
            'negative amount' => ['orders', 'Exclusive Rights,500,', 'Exclusive Rights,-500,', 'invalid_amount'],
            'refund exceeds amount' => ['orders', 'Basic Lease,29.99,29.99', 'Basic Lease,29.99,30.00', 'refund_exceeds_amount'],
            'refund without refunded status' => ['orders', 'Exclusive Rights,500,0', 'Exclusive Rights,500,1', 'refund_status_mismatch'],
            'unknown customer reference' => ['orders', 'synthetic-ord-1002,1,synthetic-cust-002', 'synthetic-ord-1002,1,synthetic-cust-009', 'unknown_customer_reference'],
            'inconsistent order lines' => ['orders', 'synthetic-ord-1001,2,synthetic-cust-001,2026-08-01T10:00:00Z', 'synthetic-ord-1001,2,synthetic-cust-001,2026-08-01T10:00:01Z', 'order_inconsistent'],
            'currency mismatch' => ['orders', 'synthetic-pay-1002,USD', 'synthetic-pay-1002,EUR', 'currency_mismatch'],
            'ordered after the watermark' => ['orders', '2026-08-03T12:30:00Z', '2026-09-03T12:30:00Z', 'ordered_after_watermark'],
            'non-UTC timestamp' => ['orders', '2026-08-03T12:30:00Z', '2026-08-03 12:30:00', 'invalid_ordered_at'],
            'tampered contract hash' => ['contracts', 'synthetic-contract-1001-2.txt,', 'synthetic-contract-1001-2.txt,'.$sha, 'contract_hash_mismatch'],
            'unknown line reference' => ['contracts', 'synthetic-ord-1002,1,synthetic-contract-1002-1.txt', 'synthetic-ord-1002,7,synthetic-contract-1002-1.txt', 'unknown_line_reference'],
            'path traversal file name' => ['contracts', ',synthetic-contract-1004-1.txt,', ',../synthetic-contract-1004-1.txt,', 'invalid_file_name'],
            'unknown media type' => ['contracts', ',synthetic-contract-1004-1.txt,', ',synthetic-contract-1004-1.docx,', 'unknown_media_type'],
            'duplicate contract file' => ['contracts', ',synthetic-contract-1001-2.txt,', ',synthetic-contract-1003-1.txt,', 'duplicate_contract_file'],
            'duplicate contract id' => ['contracts', 'synthetic-contract-1001-2,', 'synthetic-contract-1001-1,', 'duplicate_source_id'],
            'blank record' => ['orders', "USD\nsynthetic-ord-1002", "USD\n\nsynthetic-ord-1002", 'blank_record'],
            'column count mismatch' => ['customers', 'SYNTHETIC Buyer Two,no', 'SYNTHETIC Buyer Two,no,extra', 'column_count_mismatch'],
        ];
    }

    #[DataProvider('findings')]
    public function test_every_finding_withholds_the_snapshot_and_nothing_is_defaulted(string $sheet, string $search, string $replace, string $code): void
    {
        $source = self::fixture();
        $this->assertSame(1, substr_count($source['sheets'][$sheet], $search), 'Fixture search must be unique.');
        $source['sheets'][$sheet] = str_replace($search, $replace, $source['sheets'][$sheet]);
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertNull($result['snapshot']);
        $this->assertNull($result['snapshot_sha256']);
        $this->assertContains($code, array_column($result['findings'], 'code'), json_encode($result['findings']));
        // Findings are printed to the console: a source id appears only as its hash, never verbatim.
        $this->assertDoesNotMatchRegularExpression('/synthetic-(?:cust|ord|contract)-|@/', json_encode($result['findings']));
        foreach ($result['findings'] as $finding) {
            $this->assertTrue($finding['source_id_sha256'] === null || preg_match('/\A[a-f0-9]{64}\z/', $finding['source_id_sha256']) === 1);
        }
    }

    public function test_an_unreferenced_contract_file_is_a_finding(): void
    {
        $source = self::fixture();
        $source['contracts']['stray.txt'] = 'SYNTHETIC stray file';
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertNull($result['snapshot']);
        $this->assertSame(['unreferenced_contract_file'], array_column($result['findings'], 'code'));
    }

    public function test_a_tampered_original_whose_declared_hash_no_longer_matches_is_refused(): void
    {
        $source = self::fixture();
        $source['contracts']['synthetic-contract-1002-1.txt'] .= "SYNTHETIC appended clause\n";
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertNull($result['snapshot']);
        $this->assertSame(['contract_hash_mismatch'], array_column($result['findings'], 'code'));
    }

    public function test_a_missing_original_is_an_exception_that_blocks_review_and_is_never_regenerated(): void
    {
        $source = self::fixture();
        unset($source['contracts']['synthetic-contract-1002-1.txt']);
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertSame([], $result['findings']);
        $snapshot = $result['snapshot'];
        $this->assertCount(3, $snapshot['contracts']);
        $line = array_column($snapshot['lines'], null, 'source_order_id')['synthetic-ord-1002'];
        $this->assertSame(['contract_original_missing'], $snapshot['eligibility'][$line['record_key']]['blockers']);
        $this->assertSame(['ambiguous_customer_identity' => 2, 'contract_original_missing' => 2], $this->codes($snapshot['exceptions']));
    }

    public function test_an_exclusive_sold_twice_is_kept_for_both_buyers_and_flagged_without_revocation(): void
    {
        $source = self::fixture();
        $source['sheets']['orders'] .= "synthetic-ord-1009,1,synthetic-cust-001,2026-08-09T10:00:00Z,Completed,synthetic-track-03,Exclusive Rights,500,0,synthetic-pay-1009,USD\n";
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertSame([], $result['findings']);
        $snapshot = $result['snapshot'];
        $exclusive = array_values(array_filter($snapshot['lines'], static fn (array $line): bool => $line['track_source_id'] === 'synthetic-track-03'));
        $this->assertCount(2, $exclusive);
        foreach ($exclusive as $line) {
            $this->assertSame('archive_only', $line['classification']);
            $this->assertContains('duplicate_exclusive_sale', $snapshot['eligibility'][$line['record_key']]['blockers']);
        }
        $this->assertSame(2, $this->codes($snapshot['exceptions'])['duplicate_exclusive_sale']);
    }

    public function test_mapping_refusals_are_reason_codes(): void
    {
        $mapping = json_decode(self::fixture()['mapping'], true);
        foreach ([
            'mapping_currency' => fn (array $m): array => ['currency' => 'EUR'] + $m,
            'mapping_timestamp' => fn (array $m): array => ['source_as_of' => '2026-09-02T00:00:00Z'] + $m,
            'mapping_acquisition_method' => fn (array $m): array => ['acquisition_method' => 'scraped'] + $m,
            'mapping_licenses' => function (array $m): array {
                $m['licenses']['Basic Lease']['exclusive'] = 'no';

                return $m;
            },
            'mapping_status_values' => function (array $m): array {
                $m['orders']['status_values'] = ['Completed' => 'settled'];

                return $m;
            },
            'mapping_column_reuse' => function (array $m): array {
                $m['orders']['ignored_columns'] = ['Status'];

                return $m;
            },
        ] as $code => $change) {
            try {
                (new HistoryMapping)->decode(json_encode($change($mapping)));
                $this->fail($code.' accepted');
            } catch (InvalidArgumentException $error) {
                $this->assertSame($code, $error->getMessage());
            }
        }
    }

    private function codes(array $exceptions): array
    {
        $codes = array_count_values(array_column($exceptions, 'code'));
        ksort($codes);

        return $codes;
    }

    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
