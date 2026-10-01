<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

class CommerceGuardBytesTest extends TestCase
{
    #[DataProvider('guardCases')]
    public function test_guard_sql_compares_only_whole_whitelisted_identifiers(
        string $file,
        string $driver,
        array $arguments,
        string $name,
        string $table,
        string $message,
        array $columns,
        bool $mysqlComparison,
    ): void {
        $migration = require __DIR__.'/../../database/migrations/'.$file;
        [$left, $right] = $columns;
        $others = implode(' AND ', array_map(fn (string $column): string =>
            $column.'_extra = 1 AND X'.$column.' = 2 AND '.$column.'$suffix = 3 AND t.'.$column.' = 4 AND '.$column.'2 = 5',
            $columns,
        ));
        $allowed = "({$left} = {$right}) AND LENGTH({$left}) > 0 AND {$others}";
        $compared = $driver === 'mysql' && $mysqlComparison
            ? "(CAST({$left} AS BINARY) = CAST({$right} AS BINARY)) AND LENGTH(CAST({$left} AS BINARY)) > 0 AND {$others}"
            : $allowed;
        $sql = null;
        DB::shouldReceive('getDriverName')->andReturn($driver);
        DB::shouldReceive('unprepared')->once()->andReturnUsing(function (string $statement) use (&$sql): bool {
            $sql = $statement;

            return true;
        });

        (new ReflectionMethod($migration, 'guard'))->invokeArgs($migration, [...$arguments, $allowed]);

        $operation = $arguments[array_key_last($arguments)];
        $expected = $driver === 'sqlite'
            ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$compared}), 0) BEGIN SELECT RAISE(ABORT, '{$message}'); END"
            : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN IF NOT COALESCE(({$compared}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
        $this->assertSame($expected, $sql);
    }

    public static function guardCases(): iterable
    {
        $finalization = '2026_09_26_000020_test_order_finalization.php';
        $activation = '2026_09_26_000022_test_fulfillment_activation.php';
        $delivery = '2026_09_26_000023_test_owner_delivery.php';
        $cases = [
            'inventory states' => [
                $finalization, ['inventory_bytes', 'inventory_reservations', 'update'],
                'inventory_bytes', 'inventory_reservations', 'Invalid or immutable finalization evidence',
                ['NEW.state', 'OLD.state'], true,
            ],
            'promotion states' => [
                $finalization, ['promotion_bytes', 'promotion_uses', 'update'],
                'promotion_bytes', 'promotion_uses', 'Invalid or immutable finalization evidence',
                ['NEW.state', 'OLD.state'], true,
            ],
            'other finalization table stays unchanged' => [
                $finalization, ['entitlement_bytes', 'pending_entitlements', 'update'],
                'entitlement_bytes', 'pending_entitlements', 'Invalid or immutable finalization evidence',
                ['NEW.state', 'OLD.state'], false,
            ],
            'activation versions' => [
                $activation, ['bytes', 'insert'],
                'test_fulfillment_activations_bytes', 'test_fulfillment_activations',
                'Invalid or immutable test fulfillment activation',
                ['NEW.policy_version', 'NEW.canonicalization_version'], true,
            ],
            'delivery kinds' => [
                $delivery, ['test_delivery_authorizations', 'bytes', 'insert'],
                'test_delivery_authorizations_bytes', 'test_delivery_authorizations',
                'Invalid or immutable test delivery evidence',
                ['NEW.kind', 'x.kind'], true,
            ],
        ];
        foreach (['mysql', 'sqlite'] as $driver) {
            foreach ($cases as $label => [$file, $arguments, $name, $table, $message, $columns, $mysqlComparison]) {
                yield $driver.' '.$label => [$file, $driver, $arguments, $name, $table, $message, $columns, $mysqlComparison];
            }
        }
    }

    #[DataProvider('existingPredicates')]
    public function test_existing_predicates_preserve_the_previous_byte_comparison_sql(
        string $file,
        array $columns,
        string $condition,
    ): void {
        $migration = require __DIR__.'/../../database/migrations/'.$file;
        $legacy = str_replace($columns, array_map(fn (string $column): string => 'CAST('.$column.' AS BINARY)', $columns), $condition);

        $this->assertSame($legacy, $migration->bytewise($condition));
    }

    public static function existingPredicates(): iterable
    {
        yield 'paid resource consumption' => [
            '2026_09_26_000020_test_order_finalization.php', ['NEW.state', 'OLD.state'],
            "OLD.state = 'pending' AND NEW.state = 'consumed' AND OLD.consumed_at IS NULL AND NEW.consumed_at IS NOT NULL"
                .' AND OLD.attempt_id IS NOT NULL AND OLD.pending_at IS NOT NULL AND NEW.consumed_at >= OLD.pending_at',
        ];
        yield 'activation policy and canonical version' => [
            '2026_09_26_000022_test_fulfillment_activation.php', ['NEW.policy_version', 'NEW.canonicalization_version'],
            "NEW.policy_version = 'test-fulfillment-activation-v1' AND NEW.canonicalization_version = 'vasey-json-v1'",
        ];
        foreach (['NEW', 'x'] as $prefix) {
            yield 'delivery target '.$prefix => [
                '2026_09_26_000023_test_owner_delivery.php', ['NEW.kind', 'x.kind'],
                "(({$prefix}.kind = 'contract' AND {$prefix}.grant_contract_id IS NOT NULL AND {$prefix}.pending_entitlement_id IS NULL)"
                    ." OR ({$prefix}.kind IN ('master_wav', 'download_mp3', 'stems_zip') AND {$prefix}.grant_contract_id IS NULL AND {$prefix}.pending_entitlement_id IS NOT NULL))",
            ];
        }
    }
}
