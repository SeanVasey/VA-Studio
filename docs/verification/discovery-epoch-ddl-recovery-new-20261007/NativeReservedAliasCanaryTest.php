<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class NativeReservedAliasCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_native_dictionary_alias_refuses_before_creating_epoch_table_or_seed(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $pdo = DB::connection()->getPdo();
        $helper = new DiscoveryEpochRecoveryTest('test_failed_trigger_creation_retries_through_real_migrator_without_erasing_prior_data');
        $call = static fn (string $method, mixed ...$arguments): mixed => (new \ReflectionMethod($helper, $method))->invoke($helper, ...$arguments);
        $call('removeFixtureEpoch', $pdo);
        $pdo->exec('CREATE TRIGGER cde_own_insért BEFORE INSERT ON tracks FOR EACH ROW SET @independent_alias_probe = 1');
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
            $this->fail('Foreign trigger dictionary alias was adopted.');
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } catch (\PDOException $error) {
            $this->fail('Native trigger alias reached DDL after '.count($writes).' attempted writes: '.$error->getMessage());
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $call('databaseSnapshot', $pdo));
        $this->assertSame(0, DB::transactionLevel());
    }
}
