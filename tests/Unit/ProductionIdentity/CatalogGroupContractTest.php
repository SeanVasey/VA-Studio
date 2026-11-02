<?php

namespace Tests\Unit\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\CatalogGroup;
use App\Domain\Customers\ProductionIdentity\CatalogGroupPlan;
use App\Domain\Customers\ProductionIdentity\CatalogRead;
use App\Domain\Customers\ProductionIdentity\CurrentCatalog;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class CatalogGroupContractTest extends TestCase
{
    public function test_fixed_plans_preserve_original_family_names_bindings_and_nonmergeable_ordered_relation(): void
    {
        foreach ([AccountAdmissionGuards::V1, AccountAdmissionGuards::V2, AccountAdmissionGuards::OVERLAP] as $version) {
            $old = 0;
            foreach (CatalogGroup::cases() as $group) {
                $plan = CatalogGroupPlan::build($group, $version);
                $old += count($plan['families']);
                $streams = $group === CatalogGroup::I2_RESERVED_NAMES ? $plan['i2_split'] : [$plan];
                $covered = [];
                foreach ($streams as $index => $stream) {
                    self::assertSame(count($stream['bindings']) + ($group === CatalogGroup::I2_RESERVED_NAMES && $index === 1 ? 2 : 0), substr_count($stream['sql'], '?'));
                    self::assertLessThanOrEqual(1024, count($stream['bindings']));
                    self::assertSame(count($stream['families']), substr_count($stream['sql'], 'ROW_NUMBER() OVER ('));
                    self::assertStringEndsWith('ORDER BY family,slot,kind,CAST(ordinal AS UNSIGNED)', $stream['sql']);
                    self::assertStringNotContainsString('LIMIT ', $stream['sql']);
                    foreach (array_keys($stream['families']) as $familyIndex) {
                        self::assertNotContains($familyIndex, $covered);
                        $covered[] = $familyIndex;
                    }
                }
                self::assertSame(array_keys($plan['families']), $covered);
                foreach ($plan['families'] as $family) {
                    self::assertInstanceOf(CatalogRead::class, constant(CatalogRead::class.'::'.$family['enum']));
                    self::assertNotEmpty($family['names']);
                    self::assertNotEmpty($family['columns']);
                }
            }
            self::assertSame(36, $old);
        }
    }

    public function test_i2_shared_framing_reserve_and_exact_residual_boundaries(): void
    {
        $residual = new ReflectionMethod(CurrentCatalog::class, 'i2Residual');
        self::assertSame(['records' => 131072, 'bytes' => 16773120], $residual->invoke(null, ['bytes' => 4096, 'records' => 0]));
        self::assertSame(['records' => 28, 'bytes' => 7336], $residual->invoke(null, ['bytes' => 16769880, 'records' => 131044]));
        foreach ([['bytes' => 16769881, 'records' => 0], ['bytes' => 4096, 'records' => 131045], ['bytes' => 4095, 'records' => 0],
            ['bytes' => '4096', 'records' => 0], ['bytes' => true, 'records' => 0], ['bytes' => PHP_INT_MAX, 'records' => 0],
            ['bytes' => 4096, 'records' => -1], ['bytes' => 4096, 'records' => '0'], ['records' => 0, 'bytes' => 4096]] as $bad) {
            try {
                $residual->invoke(null, $bad);
                self::fail('Malformed/exhausted residual accepted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        // Independent charge arithmetic: ALL future failure headers, never one marker or a second framing reserve.
        self::assertSame(7336, 28 * (256 + strlen('[null]')));
        self::assertSame(16777216, 16769880 + 28 * (256 + strlen('[null]')));
        self::assertSame(131072, 131044 + 28);
        self::assertSame(18712, 4096 + 56 * (256 + strlen('["0"]')));
    }

    public function test_i2_first_receiver_reserves_all_future_headers_at_exact_byte_and_record_thresholds(): void
    {
        $plan = CatalogGroupPlan::build(CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2);
        $family = [0 => $plan['families'][0]];
        $wire = [];
        foreach ($plan['families'][0]['names'] as $slot => $name) {
            $wire[] = ['group_id' => CatalogGroup::I2_RESERVED_NAMES->value, 'family' => 0, 'slot' => $slot, 'kind' => 0, 'ordinal' => '0', 'payload' => '["0"]'];
        }
        $decode = new ReflectionMethod(CurrentCatalog::class, 'decodeGroup');
        // Inject only a prior private receiving charge: independent expected cost of this28-header stream=7308.
        $stage = ['bytes' => 16762572, 'records' => 131016];
        $decode->invokeArgs(null, [CatalogGroup::I2_RESERVED_NAMES, $family, $wire, &$stage, true]);
        self::assertSame(['bytes' => 16769880, 'records' => 131044], $stage);
        foreach ([['bytes' => 16762573, 'records' => 131016], ['bytes' => 16762572, 'records' => 131017]] as $start) {
            $stage = $start;
            try {
                $decode->invokeArgs(null, [CatalogGroup::I2_RESERVED_NAMES, $family, $wire, &$stage, true]);
                self::fail('First stream consumed future invalid-header reserve.');
            } catch (LogicException) {
                self::assertSame($start, $stage);
            }
        }
    }

    public function test_i2_receiver_aggregates_both_complete_streams_and_never_updates_stage_on_failure(): void
    {
        $plan = CatalogGroupPlan::build(CatalogGroup::I2_RESERVED_NAMES, AccountAdmissionGuards::V2);
        self::assertSame(['i2_split', 'families'], array_keys($plan));
        $stage = ['bytes' => 4096, 'records' => 0];
        $decode = new ReflectionMethod(CurrentCatalog::class, 'decodeGroup');
        foreach ([0, 1] as $index) {
            $wire = [];
            foreach ($plan['families'][$index]['names'] as $slot => $name) {
                $wire[] = ['group_id' => CatalogGroup::I2_RESERVED_NAMES->value, 'family' => $index, 'slot' => $slot, 'kind' => 0, 'ordinal' => '0', 'payload' => '["0"]'];
            }
            $result = $decode->invokeArgs(null, [CatalogGroup::I2_RESERVED_NAMES, [$index => $plan['families'][$index]], $wire, &$stage, $index === 0]);
            self::assertCount(28, reset($result));
            self::assertSame(4096 + ($index + 1) * 7308, $stage['bytes']);
            self::assertSame(($index + 1) * 28, $stage['records']);
        }
        $family = [1 => $plan['families'][1]];
        $good = $wire;
        $invalid = $wire;
        foreach ($invalid as &$header) {
            $header['payload'] = '[null]';
        }
        unset($header);
        foreach ([$invalid, array_slice($good, 1), [...$good, $good[0]], array_reverse($good)] as $badWire) {
            $stage = ['bytes' => 16769880, 'records' => 131044];
            $before = $stage;
            try {
                $decode->invokeArgs(null, [CatalogGroup::I2_RESERVED_NAMES, $family, $badWire, &$stage, false]);
                self::fail('Partial/invalid second family accepted.');
            } catch (LogicException) {
                self::assertSame($before, $stage, 'Failed private decoding publishes no accounting/result.');
            }
        }
        // Whole valid empty second stream fits exact aggregate record bound and the reserved byte allowance.
        $stage = ['bytes' => 16769880, 'records' => 131044];
        $decode->invokeArgs(null, [CatalogGroup::I2_RESERVED_NAMES, $family, $good, &$stage, false]);
        self::assertSame(['bytes' => 16777188, 'records' => 131072], $stage);
        foreach ([['bytes' => 16769909, 'records' => 0], ['bytes' => 4096, 'records' => 131045]] as $start) {
            $stage = $start;
            try {
                $decode->invokeArgs(null, [CatalogGroup::I2_RESERVED_NAMES, $family, $good, &$stage, false]);
                self::fail('Aggregate receiving overflow accepted.');
            } catch (LogicException) {
                self::assertSame($start, $stage);
            }
        }
    }

    public function test_ordered_cell_roundtrip_keeps_null_native_integers_numeric_text_unicode_and_key_order(): void
    {
        $families = CatalogGroupPlan::build(CatalogGroup::A2_ACCOUNT_FLOOR, AccountAdmissionGuards::V2)['families'];
        $wire = $this->emptyWire($families);
        $family = $families[0];
        $cells = [];
        $expected = [];
        foreach ($family['columns'] as $n => $column) {
            $value = $column['nullable'] ? null : ($n === 0 ? '001É é \\ "' : '');
            $cells[] = [$value === null ? 'N' : 'S', $value];
            $expected[$column['key']] = $value;
        }
        $wire[0]['payload'] = '["1"]';
        array_splice($wire, 1, 0, [$this->record(0, 1, '1', $cells)]);
        $wire[3]['payload'] = '["1"]';
        $wire[] = $this->record(2, 1, '1', [['I', '6']]);
        $actual = $this->decode($families, $wire);
        self::assertSame([$expected], $actual[CatalogRead::QD9E74E9B8F9D->value]['']);
        self::assertSame([['count' => 6]], $actual[CatalogRead::QB894B1A4D034->value]['']);
    }

    public function test_native_view_and_functional_index_nulls_are_representable_before_ownership_refusal(): void
    {
        $plans = [CatalogGroup::A0_ACCOUNT_TABLE, CatalogGroup::P1_PARENT_SHAPE, CatalogGroup::I0_IDENTITY_NAMESPACE, CatalogGroup::A1_ACCOUNT_AFTER_SHOW, CatalogGroup::I1_IDENTITY_SHAPE];
        foreach ($plans as $group) {
            foreach (CatalogGroupPlan::build($group, AccountAdmissionGuards::V2)['families'] as $family) {
                foreach ($family['columns'] as $column) {
                    if (in_array($column['key'], ['ENGINE', 'TABLE_COLLATION', 'CREATE_OPTIONS'], true)
                        || (in_array($family['enum'], ['Q54EFEC87F80D', 'Q603824C0D074'], true) && in_array($column['key'], ['COLUMN_NAME', 'COLLATION'], true))) {
                        self::assertTrue($column['nullable']);
                    }
                }
            }
        }
    }

    public function test_malformed_missing_duplicate_empty_truncated_types_ordinals_overflow_and_caps_refuse_whole_group(): void
    {
        $families = CatalogGroupPlan::build(CatalogGroup::A0_ACCOUNT_TABLE, AccountAdmissionGuards::V2)['families'];
        $empty = $this->emptyWire($families);
        self::assertSame([CatalogRead::Q3C5185ED551F->value => ['' => []]], $this->decode($families, $empty));
        $cases = [[], [$empty[0], $empty[0]]];
        foreach (['[]', '{"0":"0"}', '[0]', '["01"]', '["9223372036854775808"]', '{', '["65537"]', '["1"]', '["0",null]', '"0"', '["\ud800"]'] as $payload) {
            $row = $empty[0];
            $row['payload'] = $payload;
            $cases[] = [$row];
        }
        foreach (['family' => 1, 'slot' => 1, 'kind' => 2, 'ordinal' => '1'] as $key => $value) {
            $row = $empty[0];
            $row[$key] = $value;
            $cases[] = [$row];
        }
        $row = $empty[0];
        $row['extra'] = 0;
        $cases[] = [$row];
        $row = $empty[0];
        $row['payload'] = str_repeat(' ', 8388609);
        $cases[] = [$row];
        $valid = [['S', 'customer_accounts'], ['S', 'BASE TABLE'], ['S', 'InnoDB'], ['S', 'utf8mb4_unicode_ci'], ['S', ''], ['S', '']];
        $header = $empty[0];
        $header['payload'] = '["1"]';
        foreach ([['I', '0'], ['N', null], ['S', 1], ['X', 'value'], ['S'], ['S', 'a', 'b']] as $bad) {
            $cells = $valid;
            $cells[0] = $bad;
            $cases[] = [$header, $this->record(0, 1, '1', $cells)];
        }
        $cases[] = [$header, $this->record(0, 1, '2', $valid)];
        $cases[] = [$header, $this->record(0, 1, '1', $valid), $this->record(0, 1, '1', $valid)];
        foreach ($cases as $case) {
            try {
                $this->decode($families, $case);
                self::fail('Malformed transport admitted.');
            } catch (LogicException|\JsonException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_decimal_ordinals_above_nine_and_aggregate_wire_cap_are_exact(): void
    {
        $families = CatalogGroupPlan::build(CatalogGroup::A2_ACCOUNT_FLOOR, AccountAdmissionGuards::V2)['families'];
        $wire = $this->emptyWire($families);
        $wire[2]['payload'] = '["12"]';
        for ($n = 1; $n <= 12; $n++) {
            $wire[] = $this->record(2, 1, (string) $n, [['I', (string) $n]]);
        }
        $actual = $this->decode($families, $wire);
        self::assertSame(range(1, 12), array_column($actual[CatalogRead::QB894B1A4D034->value][''], 'count'));
        foreach (['01', '-1', '1e2', '9223372036854775808'] as $bad) {
            $broken = $this->emptyWire($families);
            $broken[2]['payload'] = '["1"]';
            $broken[] = $this->record(2, 1, '1', [['I', $bad]]);
            try {
                $this->decode($families, $broken);
                self::fail('Non-native integer admitted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
        $family = CatalogGroupPlan::build(CatalogGroup::A1_ACCOUNT_AFTER_SHOW, AccountAdmissionGuards::V2)['families'][0];
        $family['names'] = [''];
        $wire = $this->emptyWire([$family]);
        $wire[0]['payload'] = '["3"]';
        $cells = [['S', str_repeat('x', 6000000)]];
        for ($n = 1; $n <= 3; $n++) {
            $wire[] = $this->record(0, 1, (string) $n, $cells);
        }
        $this->expectException(LogicException::class);
        $this->decode([$family], $wire);
    }

    public function test_existing_standalone_entry_signatures_and_closed_parent_consumer_selection_remain_distinct(): void
    {
        foreach (['stable', 'migrationState', 'identityParentStable', 'identityParentMigrationState'] as $method) {
            $reflection = new ReflectionMethod(AccountAdmissionGuards::class, $method);
            self::assertSame(['pdo', 'driver'], array_map(fn ($p): string => $p->getName(), $reflection->getParameters()));
        }
        $root = dirname(__DIR__, 3);
        $owners = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root.'/app')) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getBasename() !== 'AccountAdmissionGuards.php') {
                $source = file_get_contents($file->getPathname());
                if (str_contains($source, 'AccountAdmissionGuards::identityParent')) {
                    $owners[] = $file->getPathname();
                }
            }
        }
        self::assertSame([$root.'/app/Domain/Customers/ProductionIdentity/IdentityMigrationOwnership.php'], $owners);
        $source = file_get_contents($root.'/app/Domain/Customers/ProductionIdentity/AccountAdmissionGuards.php');
        self::assertStringContainsString('self::inspect($pdo, $driver, false);', $source);
        self::assertStringContainsString('self::inspect($pdo, $driver, true);', $source);
    }

    public function test_missing_and_cross_group_tags_cannot_supply_another_groups_rows(): void
    {
        $group = CatalogGroup::A0_ACCOUNT_TABLE;
        $families = CatalogGroupPlan::build($group, AccountAdmissionGuards::V2)['families'];
        $header = $this->emptyWire($families)[0];
        foreach ([$header, ['group_id' => CatalogGroup::A2_ACCOUNT_FLOOR->value] + $header] as $wire) {
            try {
                (new ReflectionMethod(CurrentCatalog::class, 'decodeGroup'))->invoke(null, $group, $families, [$wire]);
                self::fail('Missing/cross-group tag admitted.');
            } catch (LogicException) {
                self::assertTrue(true);
            }
        }
    }

    private function emptyWire(array $families): array
    {
        $wire = [];
        foreach ($families as $n => $family) {
            foreach ($family['names'] as $slot => $name) {
                $wire[] = ['family' => $n, 'slot' => $slot, 'kind' => 0, 'ordinal' => '0', 'payload' => '["0"]'];
            }
        }

        return $wire;
    }

    private function record(int $family, int $kind, string $ordinal, array $cells): array
    {
        return ['family' => $family, 'slot' => 0, 'kind' => $kind, 'ordinal' => $ordinal, 'payload' => json_encode($cells, JSON_THROW_ON_ERROR)];
    }

    private function decode(array $families, array $wire): array
    {
        $group = match ($families[0]['enum']) {
            'Q3C5185ED551F' => CatalogGroup::A0_ACCOUNT_TABLE, 'QD9E74E9B8F9D' => CatalogGroup::A2_ACCOUNT_FLOOR, 'AccountTables' => CatalogGroup::A1_ACCOUNT_AFTER_SHOW
        };
        $wire = array_map(fn (array $row): array => ['group_id' => $group->value] + $row, $wire);

        return (new ReflectionMethod(CurrentCatalog::class, 'decodeGroup'))->invoke(null, $group, $families, $wire);
    }
}
