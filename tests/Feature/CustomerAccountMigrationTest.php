<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\CapabilityRollbackFixture;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerAccountMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        // Roll back the empty additive child before exercising the retained account parent.
        $this->assertDatabaseCount('transactional_notices', 0);
        $this->assertDatabaseCount('transactional_notice_attempts', 0);
        (require database_path('migrations/2026_10_06_231000_test_transactional_notifications.php'))->down();
        $this->assertDatabaseCount('membership_credit_buckets', 0);
        $this->assertDatabaseCount('membership_credit_events', 0);
        $this->assertDatabaseCount('membership_plans', 0);
        $this->assertDatabaseCount('membership_plan_versions', 0);
        // Dispose only this fresh test's verified empty child schema; operational rollback must refuse.
        // Keep foreign keys enabled and remove child tables before their referenced parents.
        foreach (['membership_credit_events', 'membership_credit_buckets', 'membership_plan_versions', 'membership_plans'] as $table) {
            Schema::drop($table);
            $this->assertFalse(Schema::hasTable($table));
        }
        (require database_path('migrations/2026_10_06_000048_customer_purchase_claims.php'))->down();
        (require database_path('migrations/2026_10_06_000045_customer_identity_challenges.php'))->down();
        // Newer families hold RESTRICT foreign keys to customer_accounts and refuse operational rollback:
        // customer_saved_tracks (242000), service projects (244000), free and paid grant origins (245000,
        // 252000), consent (250000), suppression (251000) and the production identity, feature,
        // suppression, free-grant and membership families (247000, 253000 to 259000). Native MySQL refuses
        // to drop a referenced parent (SQLSTATE 3730) and names only the first blocking constraint, so
        // dispose of every verified-empty dependent from the live catalog, leaves first, with foreign keys
        // still enforced. Dropping a table also drops its own triggers.
        $dependents = CapabilityRollbackFixture::dependents(['customer_accounts']);
        $this->assertContains('customer_saved_tracks', $dependents);
        CapabilityRollbackFixture::dropEmptyLeavesFirst($dependents);
        $this->assertSame([], CapabilityRollbackFixture::dependents(['customer_accounts']));
    }

    public function test_every_unique_identity_collision_refuses_replace_even_without_recursive_delete_triggers(): void
    {
        $f = F::account();
        F::withdraw($f);
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers=OFF');
        }
        $before = (array) DB::table('customer_accounts')->where('id', $f['account']->id)->sole();
        foreach (['id', 'public_id', 'user_id', 'owner_key'] as $column) {
            $other = F::account();
            $row = (array) DB::table('customer_accounts')->where('id', $other['account']->id)->sole();
            // A new otherwise-valid identity collides with exactly one retained unique key.
            $newUser = User::factory()->create(['is_admin' => false, 'email_verified_at' => now()]);
            $owner = bin2hex(random_bytes(32));
            DB::table('quote_owners')->insert(['owner_key' => $owner]);
            $row = array_replace($row, ['id' => 100000 + $other['account']->id, 'public_id' => (string) Str::uuid(), 'user_id' => $newUser->id, 'owner_key' => $owner, $column => $before[$column]]);
            $retained = DB::table('customer_accounts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
            try {
                DB::statement('REPLACE INTO customer_accounts ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row));
                $this->fail('REPLACE changed a retained identity via '.$column);
            } catch (QueryException) {
                $this->assertTrue(true);
            }
            $this->assertSame($retained, DB::table('customer_accounts')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        }
        $this->assertSame($before, (array) DB::table('customer_accounts')->where('id', $f['account']->id)->sole());
    }

    public function test_raw_identity_rewrites_deletion_and_access_version_replay_are_refused(): void
    {
        $f = F::account();
        $before = (array) DB::table('customer_accounts')->where('id', $f['account']->id)->sole();
        foreach ([['public_id' => (string) Str::uuid()], ['owner_key' => str_repeat('a', 64)], ['active' => false], ['access_version' => 2]] as $values) {
            try {
                DB::table('customer_accounts')->where('id', $f['account']->id)->update($values);
                $this->fail('Raw identity mutation accepted.');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
            $this->assertSame($before, (array) DB::table('customer_accounts')->where('id', $f['account']->id)->sole());
        }
        try {
            DB::table('customer_accounts')->where('id', $f['account']->id)->delete();
            $this->fail('Retained identity deleted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        F::withdraw($f);
        $this->assertSame(2, $f['account']->fresh()->access_version);
        $this->assertFalse($f['account']->fresh()->active);
    }

    public function test_populated_rollback_refuses_without_touching_retained_identity_or_guards(): void
    {
        $f = F::account();
        $migration = require database_path('migrations/2026_10_06_000040_customer_accounts.php');
        try {
            $migration->down();
            $this->fail('Populated rollback accepted.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('customer_accounts', 1);
        }
        try {
            DB::table('customer_accounts')->where('id', $f['account']->id)->delete();
            $this->fail('Rollback removed the guard.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_empty_owned_rollback_and_reinstallation_preserve_the_expected_guard_set(): void
    {
        $migration = require database_path('migrations/2026_10_06_000040_customer_accounts.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('customer_accounts'));
        $migration->up();
        F::account();
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    public function test_temporary_table_shadow_never_bypasses_retained_identity_or_removes_permanent_guards(): void
    {
        $f = F::account();
        $migration = require database_path('migrations/2026_10_06_000040_customer_accounts.php');
        DB::statement('CREATE TEMPORARY TABLE customer_accounts (id INTEGER)');
        try {
            foreach (['up', 'down'] as $direction) {
                try {
                    $migration->$direction();
                    $this->fail('Temporary shadow accepted.');
                } catch (\LogicException) {
                    $this->assertTrue(true);
                }
            }
        } finally {
            DB::statement(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.customer_accounts' : 'DROP TEMPORARY TABLE customer_accounts');
        }
        $this->assertDatabaseCount('customer_accounts', 1);
        try {
            DB::table('customer_accounts')->where('id', $f['account']->id)->delete();
            $this->fail('Permanent guard was removed.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }

    public function test_foreign_guard_name_is_preserved_before_creation_and_when_owned_table_is_absent(): void
    {
        $migration = require database_path('migrations/2026_10_06_000040_customer_accounts.php');
        $migration->down();
        DB::statement('CREATE TABLE customer_guard_fixture (id INTEGER)');
        $statement = DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER customer_accounts_insert BEFORE INSERT ON customer_guard_fixture BEGIN SELECT 1; END'
            : 'CREATE TRIGGER customer_accounts_insert BEFORE INSERT ON customer_guard_fixture FOR EACH ROW SET NEW.id = NEW.id';
        DB::unprepared($statement);
        foreach (['up', 'down'] as $direction) {
            try {
                $migration->$direction();
                $this->fail('Foreign guard accepted.');
            } catch (\LogicException) {
                $this->assertFalse(Schema::hasTable('customer_accounts'));
            }
            $this->assertSame('customer_guard_fixture', DB::getDriverName() === 'sqlite'
                ? DB::table('sqlite_master')->where('name', 'customer_accounts_insert')->value('tbl_name')
                : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'customer_accounts_insert')->value('EVENT_OBJECT_TABLE'));
        }
    }

    public function test_temporary_guard_name_is_preserved_without_removing_owned_guards(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            // MySQL has no temporary triggers; the independent table-shadow case exercises its resolution hazard.
            $this->assertSame('mysql', DB::getDriverName());

            return;
        }
        $migration = require database_path('migrations/2026_10_06_000040_customer_accounts.php');
        DB::statement('CREATE TEMPORARY TABLE customer_guard_fixture (id INTEGER)');
        DB::unprepared('CREATE TEMP TRIGGER customer_accounts_insert BEFORE INSERT ON customer_guard_fixture BEGIN SELECT 1; END');
        try {
            foreach (['up', 'down'] as $direction) {
                try {
                    $migration->$direction();
                    $this->fail('Temporary guard accepted.');
                } catch (\LogicException) {
                    $this->assertTrue(true);
                }
            }
            $this->assertSame(1, DB::table('sqlite_temp_master')->where('name', 'customer_accounts_insert')->count());
            $this->assertSame(3, DB::table('sqlite_master')->where('type', 'trigger')->where('tbl_name', 'customer_accounts')->count());
        } finally {
            DB::statement('DROP TABLE temp.customer_guard_fixture');
        }
    }
}
