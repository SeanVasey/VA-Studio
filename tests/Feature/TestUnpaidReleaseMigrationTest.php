<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

class TestUnpaidReleaseMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private function fixture(bool $release = true): array
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $f = F::started($gateway);
        if ($release) {
            $this->assertSame('released', app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0)['status']);
        }

        return $f;
    }

    public function test_unproved_release_and_all_released_resource_rewrites_are_refused(): void
    {
        $f = $this->fixture(false);
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            $this->rejected(fn () => DB::table($table)->update(['state' => 'released']));
        }
        app(ReleaseTestOrderResources::class)->release($f['order']->public_id, $f['admin'], (string) Str::uuid(), 0);
        foreach (['inventory_reservations', 'promotion_uses'] as $table) {
            $before = DB::table($table)->get()->toJson();
            foreach ([['state' => 'pending'], ['state' => 'consumed', 'consumed_at' => now()], ['attempt_id' => (string) Str::uuid()], ['state' => 'RELEASED']] as $change) {
                $this->rejected(fn () => DB::table($table)->update($change));
                $this->assertSame($before, DB::table($table)->get()->toJson());
            }
            $this->rejected(fn () => DB::table($table)->delete());
        }
    }

    public function test_proof_and_events_refuse_raw_mutation_deletion_and_replace_with_recursive_triggers_off(): void
    {
        $this->fixture();
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA recursive_triggers=OFF');
        }
        foreach (['test_unpaid_releases', 'test_unpaid_release_events'] as $table) {
            $row = (array) DB::table($table)->first();
            $before = DB::table($table)->get()->toJson();
            $this->rejected(fn () => DB::table($table)->where('id', $row['id'])->update(['request_id' => (string) Str::uuid()]));
            $this->rejected(fn () => DB::table($table)->where('id', $row['id'])->delete());
            $this->rejected(fn () => DB::statement('REPLACE INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')', array_values($row)));
            $this->assertSame($before, DB::table($table)->get()->toJson());
        }
    }

    public function test_populated_down_preserves_every_guard_and_resource(): void
    {
        $this->fixture();
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $migration = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        try {
            $migration->down();
            $this->fail('Populated release rollback accepted.');
        } catch (LogicException) {
            $this->assertDatabaseCount('test_unpaid_releases', 1);
        }
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->rejected(fn () => DB::table('inventory_reservations')->update(['state' => 'pending']));
        $this->rejected(fn () => DB::table('test_unpaid_releases')->delete());
    }

    public function test_empty_roundtrip_restores_legacy_guards_and_preserves_existing_pending_order(): void
    {
        $this->fixture(false);
        $before = PaymentFixtures::unchangedBusinessEvidence();
        $migration = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('test_unpaid_releases'));
        $this->rejected(fn () => DB::table('inventory_reservations')->update(['state' => 'released']));
        $migration->up();
        $this->assertSame($before, PaymentFixtures::unchangedBusinessEvidence());
        $this->assertDatabaseCount('test_unpaid_releases', 0);
    }

    public function test_temporary_shadow_and_foreign_guard_refuse_before_any_ddl(): void
    {
        $migration = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        DB::statement('CREATE TEMPORARY TABLE test_unpaid_releases (id INTEGER)');
        try {
            try {
                $migration->down();
                $this->fail('Temporary release shadow accepted.');
            } catch (LogicException) {
                $this->assertTrue(true);
            }
        } finally {
            DB::statement(DB::getDriverName() === 'sqlite' ? 'DROP TABLE temp.test_unpaid_releases' : 'DROP TEMPORARY TABLE test_unpaid_releases');
        }
        $this->assertTrue(Schema::hasTable('test_unpaid_releases'));
        $migration->down();
        DB::statement('CREATE TABLE synthetic_release_guard (id INTEGER)');
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? 'CREATE TRIGGER unpaid_release_proof_insert BEFORE INSERT ON synthetic_release_guard BEGIN SELECT 1; END'
            : 'CREATE TRIGGER unpaid_release_proof_insert BEFORE INSERT ON synthetic_release_guard FOR EACH ROW SET NEW.id = NEW.id');
        try {
            $migration->up();
            $this->fail('Foreign release guard accepted.');
        } catch (LogicException) {
            $this->assertFalse(Schema::hasTable('test_unpaid_release_work'));
        }
        $this->assertSame('synthetic_release_guard', DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('name', 'unpaid_release_proof_insert')->value('tbl_name')
            : DB::table('information_schema.TRIGGERS')->where('TRIGGER_SCHEMA', DB::getDatabaseName())->where('TRIGGER_NAME', 'unpaid_release_proof_insert')->value('EVENT_OBJECT_TABLE'));
    }

    public function test_foreign_empty_work_table_shape_is_retained_before_rollback_ddl(): void
    {
        Schema::table('test_unpaid_release_work', fn ($table) => $table->string('foreign_evidence')->nullable());
        $migration = require database_path('migrations/2026_10_06_000042_test_unpaid_releases.php');
        try {
            $migration->down();
            $this->fail('Foreign work schema was dropped.');
        } catch (LogicException) {
            $this->assertTrue(Schema::hasColumn('test_unpaid_release_work', 'foreign_evidence'));
            $this->assertTrue(Schema::hasTable('test_unpaid_releases'));
        }
    }

    private function rejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Retained evidence mutation accepted.');
        } catch (QueryException) {
            $this->assertTrue(true);
        }
    }
}
