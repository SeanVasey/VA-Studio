<?php

namespace Tests\Feature;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Additional actual-native metadata drift cases; no driver switch or production data. */
final class DiscoveryRecoveryIndependentCanaryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function drift(): array
    {
        return [
            'unenforced check' => ['unenforced check'],
            'changed check bound' => ['changed check bound'],
            'foreign check identity' => ['foreign check identity'],
            'table storage comment' => ['table storage comment'],
            'view identity' => ['view identity'],
        ];
    }

    #[DataProvider('drift')]
    public function test_native_extra_metadata_drift_refuses_before_any_ddl_and_preserves_permanent_rows(string $case): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        $this->assertSame(0, DB::transactionLevel());
        $pdo = DB::connection()->getPdo();
        DB::table('tracks')->insert(['title' => 'Independent retained draft', 'slug' => 'independent-retained']);
        $helper = new DiscoveryEpochRecoveryTest('test_failed_trigger_creation_retries_through_real_migrator_without_erasing_prior_data');
        $call = static fn (string $method, mixed ...$arguments): mixed => (new \ReflectionMethod($helper, $method))->invoke($helper, ...$arguments);
        $call('removeFixtureEpoch', $pdo);
        $pdo->exec(DiscoveryEpoch::tableSql('mysql'));
        $pdo->exec('INSERT INTO '.DiscoveryEpoch::TABLE.' (id, epoch, schema_version) VALUES (1, 0, 1)');
        $pdo->exec(DiscoveryEpoch::guards('mysql')['cde_own_insert']['sql']);
        switch ($case) {
            case 'unenforced check':
                $pdo->exec('ALTER TABLE '.DiscoveryEpoch::TABLE.' ALTER CHECK cde_epoch_check NOT ENFORCED');
                break;
            case 'changed check bound':
                $pdo->exec('ALTER TABLE '.DiscoveryEpoch::TABLE.' DROP CHECK cde_epoch_check, ADD CONSTRAINT cde_epoch_check CHECK (epoch >= 0 AND epoch <= '.(DiscoveryEpoch::MAX - 1).')');
                break;
            case 'foreign check identity':
                $call('removeFixtureEpoch', $pdo);
                $pdo->exec('ALTER TABLE tracks ADD CONSTRAINT CDE_ID_CHECK CHECK (1 = 1)');
                break;
            case 'table storage comment':
                $pdo->exec("ALTER TABLE ".DiscoveryEpoch::TABLE." COMMENT = 'Independent foreign storage marker'");
                break;
            case 'view identity':
                $call('removeFixtureEpoch', $pdo);
                $pdo->exec('CREATE VIEW '.DiscoveryEpoch::TABLE.' AS SELECT 1 AS id, 0 AS epoch, 1 AS schema_version');
                break;
        }
        $before = $call('databaseSnapshot', $pdo);
        $writes = [];
        $proxy = $call('faultPdo', $pdo, static function (string $operation, string $sql, string $when) use (&$writes): void {
            if ($operation === 'exec' && $when === 'before') {
                $writes[] = $sql;
            }
        });
        DB::connection()->setPdo($proxy);
        try {
            $migration = require database_path('migrations/2026_10_07_240000_catalog_discovery_epoch.php');
            $migration->up();
            $this->fail('Metadata drift was adopted: '.$case);
        } catch (LogicException) {
            $this->assertSame([], $writes);
        } finally {
            DB::connection()->setPdo($pdo);
        }
        $this->assertSame($before, $call('databaseSnapshot', $pdo));
        $this->assertSame(0, DB::transactionLevel());
        if ($case === 'view identity') {
            $pdo->exec('DROP VIEW '.DiscoveryEpoch::TABLE);
        }
    }
}
