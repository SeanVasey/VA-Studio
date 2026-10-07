<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class SQLiteReservedIdentityCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_foreign_table_in_guard_namespace_cannot_hide_behind_exact_later_trigger(): void
    {
        $this->assertSame('sqlite', DB::getDriverName());
        $pdo = DB::connection()->getPdo();
        $helper = new DiscoveryEpochRecoveryTest('test_failed_trigger_creation_retries_through_real_migrator_without_erasing_prior_data');
        $call = static fn (string $method, mixed ...$arguments): mixed => (new \ReflectionMethod($helper, $method))->invoke($helper, ...$arguments);
        $call('removeFixtureEpoch', $pdo);
        // SQLite table/index and trigger identities occupy distinct namespaces.
        $pdo->exec('CREATE TABLE cde_own_insert (id INTEGER)');
        $pdo->exec('INSERT INTO cde_own_insert (id) VALUES (9123)');
        $pdo->exec(DiscoveryEpoch::tableSql('sqlite'));
        $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
        $pdo->exec(DiscoveryEpoch::guards('sqlite')['cde_own_insert']['sql']);
        $before = $call('databaseSnapshot', $pdo);
        $writes = [];
        DB::connection()->setPdo($call('faultPdo', $pdo, static function (string $operation, string $sql, string $when) use (&$writes): void {
            if ($operation === 'exec' && $when === 'before') {
                $writes[] = $sql;
            }
        }));
        try {
            $migration = require database_path('migrations/2026_10_07_240000_catalog_discovery_epoch.php');
            $migration->up();
            $this->fail('Foreign same-name table was adopted; recovery executed '.count($writes).' writes.');
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $call('databaseSnapshot', $pdo));
    }
}
