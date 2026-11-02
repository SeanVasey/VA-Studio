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
                self::assertSame(count($plan['bindings']), substr_count($plan['sql'], '?'));
                self::assertLessThanOrEqual(1024, count($plan['bindings']));
                self::assertSame(count($plan['families']), substr_count($plan['sql'], 'ROW_NUMBER() OVER ('));
                self::assertStringEndsWith('ORDER BY family,slot,kind,CAST(ordinal AS UNSIGNED)', $plan['sql']);
                self::assertStringNotContainsString('LIMIT ', $plan['sql']);
                foreach ($plan['families'] as $family) {
                    self::assertInstanceOf(CatalogRead::class, constant(CatalogRead::class.'::'.$family['enum']));
                    self::assertNotEmpty($family['names']);
                    self::assertNotEmpty($family['columns']);
                }
            }
            self::assertSame(36, $old);
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
