<?php

namespace Tests\Unit\Migration;

use App\Domain\Migration\History\HistoryMapping;
use App\Domain\Migration\History\HistoryNormalizer;
use App\Domain\Migration\History\HistorySourceFiles;
use App\Domain\Migration\History\ProductFamilyAdapter;
use App\Support\CanonicalJson;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\LegacyHistoryFixtures;

/** Kit, service and membership sheets through their adapters, on the synthetic `families` package only. */
class ProductFamilyAdapterTest extends TestCase
{
    /** The pinned snapshot hashes of the track-only fixtures: the adapters must not move a byte of them. */
    private const TRACK_ONLY = ['baseline' => '6ce09ab8f36d807fe5e2f57bffee9a2274694aaa0f5d4d773fa03fa4fd649871',
        'later' => 'c17abad0704dbda4fe67c4405a80473f7eab2089b59febb0fdbc56e6491dbc2f'];

    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_every_family_line_has_exactly_the_track_line_shape_and_stays_archive_only(): void
    {
        $source = HistoryNormalizerTest::fixture('families');
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertSame([], $result['findings']);
        $this->assertSame(['customers' => 2, 'orders' => 8, 'lines' => 9, 'contracts' => 1, 'contract_declarations' => 1, 'contract_files' => 1,
            'findings' => 0, 'exceptions' => 8], $result['counts']);
        $snapshot = $result['snapshot'];
        $this->assertSame(HistoryNormalizer::SCHEMA, $snapshot['schema']);
        $lines = [];
        foreach ($snapshot['lines'] as $line) {
            $lines[$line['source_order_id'].'/'.$line['source_line_id']] = $line;
        }
        $shape = array_keys($lines['synthetic-ord-2001/1']);
        foreach ($lines as $name => $line) {
            $this->assertSame($shape, array_keys($line), $name);
            $this->assertSame('archive_only', $line['classification'], $name);
            $this->assertSame(HistoryNormalizer::recordHash($line), $line['record_sha256'], $name);
        }
        // A kit whose raw id equals a track id is a different product: the family namespace keeps them apart.
        $this->assertSame('synthetic-track-01', $lines['synthetic-ord-2001/1']['track_source_id']);
        $this->assertSame(['kit:synthetic-track-01', 'Sound Kit License', 'sound-kit-license', false, 1999],
            [$lines['synthetic-ord-2001/2']['track_source_id'], $lines['synthetic-ord-2001/2']['license_name'],
                $lines['synthetic-ord-2001/2']['license_label'], $lines['synthetic-ord-2001/2']['exclusive'], $lines['synthetic-ord-2001/2']['amount_minor']]);
        $this->assertSame(['service:synthetic-service-01', 'mixing-package', 15000, 15000],
            [$lines['synthetic-ord-2005/1']['track_source_id'], $lines['synthetic-ord-2005/1']['license_label'],
                $lines['synthetic-ord-2005/1']['amount_minor'], $lines['synthetic-ord-2005/1']['refunded_minor']]);
        foreach (['synthetic-ord-2006/1', 'synthetic-ord-2007/1'] as $period) {
            $this->assertSame(['membership:synthetic-plan-01', 'pro-monthly', 999], [$lines[$period]['track_source_id'], $lines[$period]['license_label'], $lines[$period]['amount_minor']]);
        }
        $this->assertSame(['kits', null, 'services', 'memberships'], array_map(ProductFamilyAdapter::familyOf(...),
            ['kit:synthetic-track-01', 'synthetic-track-01', 'service:synthetic-service-01', 'membership:synthetic-plan-01']));

        // One order may hold a track line and a kit line; its total covers both.
        $orders = array_column($snapshot['orders'], null, 'source_order_id');
        $this->assertSame(2999 + 1999, $orders['synthetic-ord-2001']['total_minor']);
        $this->assertCount(2, $orders['synthetic-ord-2001']['line_record_keys']);
        $this->assertSame('refunded', $orders['synthetic-ord-2005']['status']);

        // No family line can be put to a grant review, whatever else is true of it; the track line beside it can.
        foreach ($lines as $name => $line) {
            $blockers = $snapshot['eligibility'][$line['record_key']]['blockers'];
            $this->assertSame(ProductFamilyAdapter::familyOf($line['track_source_id']) !== null, in_array('product_family_not_reviewable', $blockers, true), $name);
        }
        $this->assertTrue($snapshot['eligibility'][$lines['synthetic-ord-2001/1']['record_key']]['eligible']);

        // Consent stays unknown, and ignored family columns (notes, billing periods) are not retained.
        $this->assertSame(['unknown'], array_values(array_unique(array_column($snapshot['customers'], 'marketing_consent'))));
        $encoded = CanonicalJson::encode($snapshot);
        foreach (['never retained', '2026-09-05', 'Period'] as $ignored) {
            $this->assertStringNotContainsString($ignored, $encoded);
        }
        $this->assertSame(['customers', 'orders', 'contracts', 'kit_orders', 'service_orders', 'membership_orders'], array_keys($snapshot['inputs']['sheets']));
        $this->assertSame(hash('sha256', $source['sheets']['kit_orders']), $snapshot['inputs']['sheets']['kit_orders']['sha256']);
        $this->assertSame($result, (new HistoryNormalizer)->normalize(HistoryNormalizerTest::fixture('families')));
    }

    public function test_track_only_packages_normalize_to_the_same_bytes_as_before_the_adapters(): void
    {
        foreach (self::TRACK_ONLY as $package => $sha) {
            $result = (new HistoryNormalizer)->normalize(HistoryNormalizerTest::fixture($package));
            $this->assertSame($sha, $result['snapshot_sha256'], $package);
            $this->assertSame([], $result['mapping']['product_families']);
        }
    }

    public static function findings(): array
    {
        return [
            'unknown terms value' => ['kit_orders.csv', 'Sound Kit License,19.99', 'Free Kit,19.99', 'kit_orders', 'unknown_terms_value', 'terms'],
            'product id outside the synthetic namespace' => ['kit_orders.csv', 'Completed,synthetic-track-01', 'Completed,kit-01', 'kit_orders', 'synthetic_identity_required', 'product_source_id'],
            'missing product id' => ['service_orders.csv', 'synthetic-service-01', '', 'service_orders', 'missing_source_id', 'product_source_id'],
            'unknown status' => ['membership_orders.csv', '2026-07-06T10:00:00Z,Completed', '2026-07-06T10:00:00Z,Trialing', 'membership_orders', 'unknown_status_value', 'status'],
            'refund beyond the amount' => ['service_orders.csv', 'Mixing Package,150,150', 'Mixing Package,150,151', 'service_orders', 'refund_exceeds_amount', 'refunded_amount'],
            'unknown customer' => ['membership_orders.csv', 'synthetic-ord-2006,1,synthetic-cust-101', 'synthetic-ord-2006,1,synthetic-cust-999', 'membership_orders', 'unknown_customer_reference', 'source_customer_id'],
            'line id reused across sheets' => ['kit_orders.csv', 'synthetic-ord-2001,2,', 'synthetic-ord-2001,1,', 'kit_orders', 'duplicate_source_id', 'source_line_id'],
            'order cells differ between sheets' => ['kit_orders.csv', '2026-08-01T10:00:00Z,Completed', '2026-08-01T11:00:00Z,Completed', 'kit_orders', 'order_inconsistent', null],
            'unmapped family column' => ['service_orders.csv', 'Buyer Notes', 'Buyer Comments', 'service_orders', 'unmapped_column', 'Buyer Comments'],
            // A track id inside a family namespace could later be read as a kit: refused, never interpreted.
            'track id in a reserved namespace' => ['orders.csv', 'Completed,synthetic-track-09', 'Completed,kit:synthetic-track-09', 'orders', 'synthetic_identity_required', 'track_source_id'],
        ];
    }

    #[DataProvider('findings')]
    public function test_a_family_finding_names_its_own_sheet_and_withholds_the_snapshot(string $file, string $search, string $replace, string $sheet, string $code, ?string $column): void
    {
        $result = (new HistoryNormalizer)->normalize($this->changed($file, $search, $replace));
        $this->assertNull($result['snapshot']);
        $this->assertNull($result['snapshot_sha256']);
        $this->assertContains([$sheet, $code, $column], array_map(static fn (array $finding): array => [$finding['sheet'], $finding['code'], $finding['column']], $result['findings']),
            json_encode($result['findings']));
    }

    public function test_a_real_track_id_in_a_family_namespace_is_refused_rather_than_read_as_a_family_line(): void
    {
        // Outside the synthetic namespace rule, `kit:` on the orders sheet is the reserved-namespace finding itself.
        $source = HistoryNormalizerTest::fixture('families');
        $source['mapping'] = str_replace('"synthetic_fixture"', '"official_export"', $source['mapping']);
        $source['sheets']['orders'] = str_replace('Completed,synthetic-track-09', 'Completed,membership:plan-9', $source['sheets']['orders']);
        $result = (new HistoryNormalizer)->normalize($source);
        $this->assertNull($result['snapshot']);
        $this->assertSame([['orders', 'reserved_family_namespace', 'track_source_id']],
            array_map(static fn (array $finding): array => [$finding['sheet'], $finding['code'], $finding['column']], $result['findings']));
    }

    public function test_family_mapping_refusals_are_reason_codes_and_a_family_is_never_exclusive(): void
    {
        $mapping = json_decode(HistoryNormalizerTest::fixture('families')['mapping'], true);
        foreach ([
            'mapping_family_exclusive' => function (array $m): array {
                $m['product_families']['kits']['terms']['Sound Kit License']['exclusive'] = true;

                return $m;
            },
            'mapping_product_families' => function (array $m): array {
                $m['product_families']['merch'] = $m['product_families']['kits'];

                return $m;
            },
            'mapping_family_terms' => function (array $m): array {
                $m['product_families']['memberships']['terms'] = [];

                return $m;
            },
            'mapping_columns' => function (array $m): array {
                unset($m['product_families']['services']['columns']['terms']);

                return $m;
            },
            'mapping_column_reuse' => function (array $m): array {
                $m['product_families']['services']['ignored_columns'] = ['Package'];

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

    public function test_an_undeclared_family_sheet_refuses_the_package_and_a_declared_one_is_required(): void
    {
        // A family sheet the mapping does not declare would be history silently left out.
        $undeclared = LegacyHistoryFixtures::privateCopy('baseline', function (array $parts): array {
            $parts['families']['kit_orders.csv'] = "Order ID\nsynthetic-ord-1\n";

            return $parts;
        });
        $this->refused('source_undeclared_family_sheet', fn () => (new HistorySourceFiles)->read($undeclared));

        $missing = LegacyHistoryFixtures::privateCopy('families', function (array $parts): array {
            unset($parts['families']['membership_orders.csv']);

            return $parts;
        });
        $this->refused('source_not_private', fn () => (new HistorySourceFiles)->read($missing));

        $source = HistoryNormalizerTest::fixture('families');
        unset($source['sheets']['service_orders']);
        $this->refused('service_orders_missing', fn () => (new HistoryNormalizer)->normalize($source));

        $this->assertCount(6, (new HistorySourceFiles)->read(LegacyHistoryFixtures::privateCopy('families'))['sheets']);
    }

    private function changed(string $file, string $search, string $replace): array
    {
        $source = HistoryNormalizerTest::fixture('families');
        $sheet = str_replace('.csv', '', $file);
        $this->assertStringContainsString($search, $source['sheets'][$sheet]);
        $source['sheets'][$sheet] = preg_replace('/'.preg_quote($search, '/').'/', $replace, $source['sheets'][$sheet], 1);

        return $source;
    }

    private function refused(string $code, callable $read): void
    {
        try {
            $read();
            $this->fail($code.' accepted');
        } catch (InvalidArgumentException $error) {
            $this->assertSame($code, $error->getMessage());
        }
    }
}
