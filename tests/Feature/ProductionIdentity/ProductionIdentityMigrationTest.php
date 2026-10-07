<?php

namespace Tests\Feature\ProductionIdentity;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentitySchema;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

class ProductionIdentityMigrationTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_07_247000_production_customer_identity.php');
    }

    public function test_exact_empty_prefix_is_resumed_and_empty_down_up_is_reviewable(): void
    {
        $migration = $this->migration();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $migration->down();
        $tables = array_keys(IdentitySchema::definitions());
        // Simulate an interruption in the table phase, then one in the guard phase.
        foreach (array_slice($tables, 0, 3) as $table) {
            $pdo->exec(IdentitySchema::tableSql($table, $driver));
        }
        $migration->up();
        [$present, $populated] = (new IdentityMigrationOwnership)->inspect($pdo, $driver);
        $this->assertCount(28, $present);
        $this->assertNotContains(false, $present);
        $this->assertFalse($populated);
        $guards = IdentitySchema::guards($driver);
        foreach (array_slice(array_reverse(array_keys($guards)), 0, 4) as $guard) {
            $pdo->exec('DROP TRIGGER `'.$guard.'`');
        }
        $migration->up();
        $this->assertNotContains(false, (new IdentityMigrationOwnership)->inspect($pdo, $driver)[0]);
        $migration->down();
        $migration->up();
        $this->assertNotContains(false, (new IdentityMigrationOwnership)->inspect($pdo, $driver)[0]);
    }

    public function test_retained_history_refuses_down_and_missing_guard_refuses_repair_before_ddl(): void
    {
        $this->requestIdentity();
        $migration = $this->migration();
        $pdo = DB::connection()->getPdo();
        try {
            $migration->down();
            $this->fail();
        } catch (LogicException) {
        }
        $this->assertSame(1, DB::table('production_identity_challenges')->count());
        $pdo->exec('DROP TRIGGER pi_outcomes_delete');
        try {
            $migration->up();
            $this->fail();
        } catch (LogicException) {
        }
        $this->assertFalse((new IdentitySchema)::definitions() === []);
        $this->assertSame(1, DB::table('production_identity_challenges')->count());
        $guard = IdentitySchema::guards(DB::getDriverName())['pi_outcomes_delete'];
        $pdo->exec($guard['sql']);
    }

    public function test_foreign_view_dependency_and_temporary_owned_shadow_refuse_before_any_ddl(): void
    {
        $pdo = DB::connection()->getPdo();
        $migration = $this->migration();
        $pdo->exec('CREATE VIEW foreign_identity_view AS SELECT id FROM production_identity_origins');
        try {
            $migration->down();
            $this->fail();
        } catch (LogicException) {
        }
        $this->assertNotContains(false, (function () use ($pdo) {
            $pdo->exec('DROP VIEW foreign_identity_view');

            return (new IdentityMigrationOwnership)->inspect($pdo, DB::getDriverName())[0];
        })());
        $migration->down();
        $pdo->exec('CREATE TEMPORARY TABLE production_identity_addresses (id integer)');
        try {
            $migration->up();
            $this->fail();
        } catch (LogicException) {
        }
        $pdo->exec('DROP TABLE production_identity_addresses');
        $this->assertNotContains(true, (new IdentityMigrationOwnership)->inspect($pdo, DB::getDriverName())[0]);
        $migration->up();
    }

    public function test_raw_replacement_update_and_delete_cannot_rewrite_retained_challenge(): void
    {
        $this->requestIdentity();
        $pdo = DB::connection()->getPdo();
        $before = DB::table('production_identity_challenges')->first();
        foreach (['DELETE FROM production_identity_challenges', "UPDATE production_identity_challenges SET purpose='recover'"] as $sql) {
            try {
                $pdo->exec($sql);
                $this->fail();
            } catch (\PDOException) {
            }
            $this->assertEquals($before, DB::table('production_identity_challenges')->first());
        }
        $replace = DB::getDriverName() === 'mysql' ? 'REPLACE INTO' : 'INSERT OR REPLACE INTO';
        try {
            $pdo->exec($replace.' production_identity_challenges SELECT * FROM production_identity_challenges');
            $this->fail();
        } catch (\PDOException) {
        }
        $this->assertEquals($before, DB::table('production_identity_challenges')->first());
    }

    public function test_native_dictionary_unicode_guard_alias_on_foreign_table_is_refused(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL dictionary collation alias requires native MySQL.');
        }
        $migration = $this->migration();
        $migration->down();
        $pdo = DB::connection()->getPdo();
        $pdo->exec('CREATE TABLE foreign_identity_alias (id integer) ENGINE=InnoDB');
        $pdo->exec('CREATE TRIGGER `pi_addresses_insért` BEFORE INSERT ON foreign_identity_alias FOR EACH ROW SET NEW.id=NEW.id');
        try {
            $migration->up();
            $this->fail();
        } catch (LogicException) {
        }
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='production_identity_addresses'")->fetchColumn());
        $pdo->exec('DROP TRIGGER `pi_addresses_insért`');
        $pdo->exec('DROP TABLE foreign_identity_alias');
        $migration->up();
    }
}
