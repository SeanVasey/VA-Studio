<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchemaInstaller;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTrackPreparationFixtures;
use Tests\TestCase;

class ProductionCheckoutMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_246000_production_checkout';

    public function test_exact_installed_graph_repeats_without_writes_and_preserves_records(): void
    {
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $f = ProductionTrackPreparationFixtures::prepared();
        $row = ['public_id' => '00000000-0000-4000-8000-000000000001', 'created_at' => '2026-10-07T12:00:00Z',
            ...Evidence::seal(['schema_version' => 1, 'purpose' => 'synthetic_authority_fixture', 'public_id' => '00000000-0000-4000-8000-000000000001', 'created_at' => '2026-10-07T12:00:00Z']),
            'candidate_id' => $f['candidate']->id, 'owner_user_id' => $f['actor']->id,
            'request_key' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64)];
        DB::table(CheckoutSchema::TABLES['authority'])->insert($row);
        $before = DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson();
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE|REPLACE)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        (new CheckoutSchemaInstaller)->up();
        $this->assertSame([], $writes);
        $this->assertSame($before, DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson());
        try {
            DB::table(CheckoutSchema::TABLES['authority'])->update(['payload_hash' => str_repeat('0', 64)]);
            $this->fail('Immutable authority changed.');
        } catch (QueryException) {
            $this->assertSame($before, DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson());
        }
    }

    public static function prefixes(): array
    {
        return [[0], [1], [5], [10], [-3], [-1]];
    }

    #[DataProvider('prefixes')]
    public function test_exact_empty_prefix_resumes_only_missing_objects(int $count): void
    {
        $this->removeFixture();
        $statements = CheckoutSchema::statements();
        if ($count < 0) {
            $count += count($statements);
        }
        foreach (array_slice($statements, 0, $count, true) as $definition) {
            DB::unprepared($definition['statement']);
        }
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/\A\s*CREATE\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        (new CheckoutSchemaInstaller)->up();
        $this->assertSame(array_column(array_slice($statements, $count, null, true), 'statement'), $writes);
        $writes = [];
        (new CheckoutSchemaInstaller)->up();
        $this->assertSame([], $writes);
    }

    public function test_foreign_reserved_namespace_refuses_before_writes(): void
    {
        $this->removeFixture();
        DB::unprepared('CREATE TABLE pco_0_insert (marker INTEGER)');
        DB::table('pco_0_insert')->insert(['marker' => 9123]);
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('Foreign reserved object adopted.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertSame(9123, DB::table('pco_0_insert')->value('marker'));
        }
    }

    public function test_before_and_after_creation_failures_resume_exact_prefixes_without_changing_parent_rows_or_guards(): void
    {
        DB::table('tracks')->insert(['title' => 'Synthetic retained draft', 'slug' => 'checkout-retained-draft']);
        $parents = DB::table('tracks')->orderBy('id')->get()->toJson();
        $statements = CheckoutSchema::statements();
        $firstTrigger = array_search('pco_0_insert', array_keys($statements), true);
        $points = array_unique([0, intdiv($firstTrigger, 2), $firstTrigger - 1, $firstTrigger, count($statements) - 2, count($statements) - 1]);
        foreach ($points as $point) {
            foreach (['before', 'after'] as $when) {
                $this->removeFixture();
                $state = (object) ['active' => true, 'index' => -1];
                DB::connection()->beforeExecuting(function (string $sql) use ($state, $point, $when): void {
                    if ($state->active && str_starts_with($sql, 'CREATE ')) {
                        $state->index++;
                        if ($when === 'before' && $state->index === $point) {
                            throw new \RuntimeException('Synthetic checkout DDL interruption.');
                        }
                    }
                });
                DB::listen(function (QueryExecuted $query) use ($state, $point, $when): void {
                    if ($state->active && $when === 'after' && str_starts_with($query->sql, 'CREATE ') && $state->index === $point) {
                        throw new \RuntimeException('Synthetic checkout DDL interruption.');
                    }
                });
                try {
                    (new CheckoutSchemaInstaller)->up();
                    $this->fail('DDL interruption not reached.');
                } catch (\RuntimeException $error) {
                    $this->assertSame('Synthetic checkout DDL interruption.', $error->getMessage());
                } finally {
                    $state->active = false;
                }
                $surviving = $this->ownedGuards();
                $successful = $point + ($when === 'after' ? 1 : 0);
                $this->assertSame(0, DB::transactionLevel());
                $this->assertSame($parents, DB::table('tracks')->orderBy('id')->get()->toJson());
                $writes = [];
                $retry = (object) ['active' => true];
                DB::listen(function (QueryExecuted $event) use (&$writes, $retry): void {
                    if ($retry->active && str_starts_with($event->sql, 'CREATE ')) {
                        $writes[] = $event->sql;
                    }
                });
                (new CheckoutSchemaInstaller)->up();
                $retry->active = false;
                $this->assertSame(array_column(array_slice($statements, $successful, null, true), 'statement'), $writes);
                $this->assertSame($surviving, array_intersect_key($this->ownedGuards(), $surviving));
                $this->assertSame($parents, DB::table('tracks')->orderBy('id')->get()->toJson());
            }
        }
    }

    public function test_populated_partial_installation_preserves_all_records_and_surviving_guards_before_refusal(): void
    {
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        $f = ProductionTrackPreparationFixtures::prepared();
        DB::table(CheckoutSchema::TABLES['authority'])->insert(['public_id' => '00000000-0000-4000-8000-000000000001',
            'created_at' => '2026-10-07T12:00:00Z', ...Evidence::seal(['schema_version' => 1, 'purpose' => 'synthetic_authority_fixture']),
            'candidate_id' => $f['candidate']->id, 'owner_user_id' => $f['actor']->id,
            'request_key' => str_repeat('a', 64), 'request_hash' => str_repeat('b', 64)]);
        DB::unprepared('DROP TRIGGER pco_9_delete');
        $rows = DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson();
        $guards = $this->ownedGuards();
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('Populated incomplete installation adopted.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertSame($rows, DB::table(CheckoutSchema::TABLES['authority'])->get()->toJson());
            $this->assertSame($guards, $this->ownedGuards());
        }
    }

    public function test_interior_guard_gap_is_not_adopted_as_a_prefix(): void
    {
        DB::unprepared('DROP TRIGGER pco_2_update');
        $guards = $this->ownedGuards();
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (str_starts_with($event->sql, 'CREATE ')) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('Interior gap silently filled.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertSame($guards, $this->ownedGuards());
        }
    }

    public function test_sqlite_duplicate_table_and_trigger_namespace_cannot_hide_a_foreign_marker(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite has separate table and trigger namespaces.');
        }
        $this->removeFixture();
        $definitions = CheckoutSchema::statements();
        DB::unprepared($definitions[CheckoutSchema::TABLES['authority']]['statement']);
        DB::unprepared('CREATE TABLE pco_0_insert (marker INTEGER)');
        DB::table('pco_0_insert')->insert(['marker' => 9123]);
        DB::unprepared($definitions['pco_0_insert']['statement']);
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (str_starts_with($event->sql, 'CREATE ')) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('Foreign table hidden behind exact trigger.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertSame(9123, DB::table('pco_0_insert')->value('marker'));
        }
    }

    public function test_native_dictionary_accent_alias_is_rejected_before_creating_the_first_owned_table(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual MySQL dictionary collation is required.');
        }
        $this->removeFixture();
        DB::unprepared('CREATE TRIGGER pco_0_insért BEFORE INSERT ON tracks FOR EACH ROW SET @production_checkout_synthetic_marker = 9123');
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (str_starts_with($event->sql, 'CREATE ')) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('MySQL reserved accent alias created partial owned objects.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertFalse(DB::getSchemaBuilder()->hasTable(CheckoutSchema::TABLES['authority']));
            $this->assertSame('pco_0_insért', DB::selectOne("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = 'pco_0_insért'")->TRIGGER_NAME);
        }
    }

    public static function nativeForeignConstraintNames(): array
    {
        return [['pco_3_f_review_id'], ['PCO_3_F_REVIEW_ID'], ['pco_3_f_reviéw_id']];
    }

    #[DataProvider('nativeForeignConstraintNames')]
    public function test_native_foreign_constraint_dictionary_identity_refuses_before_any_owned_ddl(string $foreignName): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual MySQL schema-global FK dictionary is required.');
        }
        $this->removeFixture();
        DB::unprepared('CREATE TABLE synthetic_foreign_fk_holder (marker INT NOT NULL, owner_id BIGINT UNSIGNED NULL, CONSTRAINT `'.$foreignName.'` FOREIGN KEY (owner_id) REFERENCES users(id)) ENGINE=InnoDB');
        DB::table('synthetic_foreign_fk_holder')->insert(['marker' => 9123, 'owner_id' => null]);
        $constraints = array_map(fn ($row): array => (array) $row, DB::select("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='synthetic_foreign_fk_holder' ORDER BY CONSTRAINT_NAME"));
        $writes = [];
        DB::listen(function (QueryExecuted $event) use (&$writes): void {
            if (preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('Foreign constraint dictionary identity caused partial checkout DDL.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertFalse(DB::getSchemaBuilder()->hasTable(CheckoutSchema::TABLES['authority']));
            $this->assertSame(9123, DB::table('synthetic_foreign_fk_holder')->value('marker'));
            $this->assertSame($constraints, array_map(fn ($row): array => (array) $row, DB::select("SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='synthetic_foreign_fk_holder' ORDER BY CONSTRAINT_NAME")));
        }
    }

    public function test_native_unowned_stored_routine_reference_refuses_before_any_owned_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual retained MySQL stored routine metadata is required.');
        }
        $this->removeFixture();
        DB::unprepared('CREATE PROCEDURE synthetic_foreign_checkout_reader() SELECT COUNT(*) FROM production_checkout_orders');
        $before = (array) DB::selectOne("SELECT ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='synthetic_foreign_checkout_reader'");
        $writes = [];
        $tracking = (object) ['active' => true];
        DB::listen(function (QueryExecuted $event) use (&$writes, $tracking): void {
            if ($tracking->active && preg_match('/\A\s*(?:CREATE|ALTER|DROP|INSERT|UPDATE|DELETE)\b/i', $event->sql)) {
                $writes[] = $event->sql;
            }
        });
        try {
            (new CheckoutSchemaInstaller)->up();
            $this->fail('Unowned stored routine acquired a new checkout source.');
        } catch (\LogicException) {
            $this->assertSame([], $writes);
            $this->assertFalse(DB::getSchemaBuilder()->hasTable(CheckoutSchema::TABLES['authority']));
            $this->assertSame($before, (array) DB::selectOne("SELECT ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() AND ROUTINE_NAME='synthetic_foreign_checkout_reader'"));
        } finally {
            $tracking->active = false;
            DB::unprepared('DROP PROCEDURE synthetic_foreign_checkout_reader');
        }
    }

    public function test_final_assertion_interruption_and_repository_log_uncertainty_retry_without_duplicate_bookkeeping(): void
    {
        foreach (['final_assertion', 'migration_ended', 'before_log', 'after_log'] as $failure) {
            DB::table('migrations')->where('migration', self::MIGRATION)->delete();
            $this->removeFixture();
            $state = (object) ['active' => true, 'createdLast' => false];
            app('events')->listen(MigrationEnded::class, function (MigrationEnded $event) use ($state, $failure): void {
                if ($state->active && $failure === 'migration_ended' && $event->method === 'up') {
                    throw new \RuntimeException('Synthetic checkout bookkeeping interruption.');
                }
            });
            DB::connection()->beforeExecuting(function (string $sql) use ($state, $failure): void {
                if ($state->active && (($failure === 'before_log' && preg_match('/\Ainsert into [`"]migrations[`"] /i', $sql))
                    || ($failure === 'final_assertion' && $state->createdLast && preg_match('/\ASELECT /i', $sql)))) {
                    throw new \RuntimeException('Synthetic checkout bookkeeping interruption.');
                }
            });
            DB::listen(function (QueryExecuted $event) use ($state, $failure): void {
                if (str_starts_with($event->sql, 'CREATE TRIGGER pco_9_delete ')) {
                    $state->createdLast = true;
                }
                if ($state->active && $failure === 'after_log' && preg_match('/\Ainsert into [`"]migrations[`"] /i', $event->sql)) {
                    throw new \RuntimeException('Synthetic checkout bookkeeping interruption.');
                }
            });
            try {
                $this->migrate();
                $this->fail('Bookkeeping interruption not reached.');
            } catch (\RuntimeException $error) {
                $this->assertSame('Synthetic checkout bookkeeping interruption.', $error->getMessage());
            } finally {
                $state->active = false;
            }
            $this->assertSame($failure === 'after_log' ? 1 : 0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
            $guards = $this->ownedGuards();
            $this->assertCount(30, $guards);
            $writes = [];
            $retry = (object) ['active' => true];
            DB::listen(function (QueryExecuted $event) use (&$writes, $retry): void {
                if ($retry->active && str_starts_with($event->sql, 'CREATE ')) {
                    $writes[] = $event->sql;
                }
            });
            $this->assertSame(0, $this->migrate());
            $bookkeeping = DB::table('migrations')->orderBy('id')->get()->toJson();
            $this->assertSame(0, $this->migrate());
            $retry->active = false;
            $this->assertSame([], $writes);
            $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
            $this->assertSame($bookkeeping, DB::table('migrations')->orderBy('id')->get()->toJson());
            $this->assertSame($guards, $this->ownedGuards());
        }
    }

    private function ownedGuards(): array
    {
        $native = DB::getDriverName() === 'mysql';
        $raw = DB::select($native ? 'SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME'
            : "SELECT * FROM sqlite_master WHERE type = 'trigger' ORDER BY name");
        $guards = [];
        foreach ($raw as $record) {
            $row = (array) $record;
            $name = $row[$native ? 'TRIGGER_NAME' : 'name'];
            if (str_starts_with($name, 'pco_')) {
                $guards[$name] = $row;
            }
        }

        return $guards;
    }

    private function migrate(): int
    {
        return Artisan::call('migrate', ['--path' => [database_path('migrations/'.self::MIGRATION.'.php')], '--realpath' => true, '--force' => true]);
    }

    private function removeFixture(): void
    {
        foreach (array_reverse(CheckoutSchema::statements(), true) as $name => $definition) {
            if ($definition['type'] !== 'trigger') {
                continue;
            }
            DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
        }
        foreach (array_reverse(CheckoutSchema::TABLES) as $table) {
            DB::unprepared('DROP TABLE IF EXISTS '.$table);
        }
    }
}
