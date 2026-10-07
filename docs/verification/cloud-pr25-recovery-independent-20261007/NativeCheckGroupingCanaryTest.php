<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

final class NativeCheckGroupingCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_different_check_grouping_cannot_be_normalized_into_owned_constraint(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $pdo = DB::connection()->getPdo();
        $helper = new DiscoveryEpochRecoveryTest('test_failed_trigger_creation_retries_through_real_migrator_without_erasing_prior_data');
        $call = static fn (string $method, mixed ...$arguments): mixed => (new \ReflectionMethod($helper, $method))->invoke($helper, ...$arguments);
        $call('removeFixtureEpoch', $pdo);
        $pdo->exec(DiscoveryEpoch::tableSql('mysql'));
        $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
        $pdo->exec('ALTER TABLE '.DiscoveryEpoch::TABLE.' DROP CHECK cde_epoch_check, ADD CONSTRAINT cde_epoch_check CHECK (epoch >= (0 AND epoch) <= '.DiscoveryEpoch::MAX.')');
        // Demonstrate that this enforced CHECK now permits a negative epoch.
        $pdo->exec('UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = -1');
        $this->assertSame(-1, (int) DB::table(DiscoveryEpoch::TABLE)->value('epoch'));
        $pdo->exec('UPDATE '.DiscoveryEpoch::TABLE.' SET epoch = 0');
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
            $this->fail('Semantically changed CHECK was adopted; recovery executed '.count($writes).' writes.');
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $call('databaseSnapshot', $pdo));
    }
}
