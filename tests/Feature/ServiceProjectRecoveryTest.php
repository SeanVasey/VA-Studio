<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\ServiceProjectSchema;
use App\Domain\Services\ServiceDrafts;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PrivateProductDraftFixtures;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

final class ServiceProjectRecoveryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_244000_service_projects';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    private function resetOwned(): void
    {
        Schema::drop('service_project_events');
        Schema::drop('service_projects');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
    }

    private function migrate(): void
    {
        app('migrator')->run([database_path('migrations/'.self::MIGRATION.'.php')]);
    }

    private function prior(): array
    {
        return array_map(fn (string $table): array => DB::table($table)->when($table === 'migrations', fn ($query) => $query->where('migration', '!=', self::MIGRATION))
            ->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), ['service_drafts', 'service_draft_versions', 'migrations']);
    }

    private function schema(): array
    {
        if (DB::getDriverName() === 'sqlite') {
            return array_map(fn ($row): array => (array) $row, DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE name LIKE 'service_project%' OR tbl_name LIKE 'service_project%' ORDER BY type, name"));
        }

        return array_map(fn (string $catalog): array => array_map(fn ($row): array => (array) $row, DB::select('SELECT * FROM information_schema.'.$catalog.' WHERE '.($catalog === 'TRIGGERS' ? 'TRIGGER_SCHEMA' : 'TABLE_SCHEMA').' = DATABASE() AND '.($catalog === 'TRIGGERS' ? 'EVENT_OBJECT_TABLE' : 'TABLE_NAME')." IN ('service_projects', 'service_project_events') ORDER BY ".match ($catalog) {
            'TABLES' => 'TABLE_NAME', 'COLUMNS' => 'TABLE_NAME, ORDINAL_POSITION', 'STATISTICS' => 'TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX', 'TRIGGERS' => 'TRIGGER_NAME',
        })), ['TABLES', 'COLUMNS', 'STATISTICS', 'TRIGGERS']);
    }

    public function test_real_migrator_retries_every_committed_statement_prefix_and_retains_prior_graph_and_ledger(): void
    {
        $actor = LicenseFixtures::admin();
        $drafts = app(ServiceDrafts::class);
        $drafts->applyReviewed($drafts->review(null, PrivateProductDraftFixtures::payload('service'), $actor), $actor);
        $prior = $this->prior();
        $this->resetOwned();
        $seen = [];
        $target = null;
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$seen, &$target, &$count): void {
            if (preg_match('/\A(?:create table|alter table|create unique index|CREATE TRIGGER) ["`]?service_project/', $query->sql)) {
                $seen[] = $query->sql;
                $count++;
                if ($target === $count) {
                    $target = null;
                    throw new RuntimeException('Synthetic committed service statement interruption.');
                }
            }
        });
        $this->migrate();
        $statements = $seen;
        $this->assertGreaterThanOrEqual(13, count($statements));
        foreach (array_keys($statements) as $boundary) {
            $this->resetOwned();
            $seen = [];
            $count = 0;
            $target = $boundary + 1;
            try {
                $this->migrate();
                $this->fail('Actual migration must observe the committed-statement fault.');
            } catch (RuntimeException $error) {
                $this->assertSame('Synthetic committed service statement interruption.', $error->getMessage());
            }
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
            $this->assertSame($prior, $this->prior());
            $seen = [];
            $this->migrate();
            $this->assertSame(array_slice($statements, $boundary + 1), $seen, 'Retry must append only the missing suffix.');
            $this->assertSame($prior, $this->prior());
            $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        }
    }

    public function test_final_guard_and_migration_log_uncertainty_retry_preserves_retained_encrypted_evidence(): void
    {
        $f = F::setup();
        F::accept($f);
        $rows = array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), ['service_projects', 'service_project_events']);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->schema();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_starts_with($query->sql, 'insert into "migrations"') || $armed && str_starts_with($query->sql, 'insert into `migrations`')) {
                $armed = false;
                throw new RuntimeException('Synthetic response loss after durable migration log.');
            }
        });
        try {
            $this->migrate();
            $this->fail('Actual durable log callback must fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic response loss after durable migration log.', $error->getMessage());
        }
        $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        $this->migrate();
        ServiceProjectSchema::install();
        $this->assertSame($before, $this->schema());
        $this->assertSame($rows, array_map(fn (string $table): array => DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(), ['service_projects', 'service_project_events']));
    }

    public function test_noncontiguous_guard_and_changed_table_metadata_are_refused_before_any_writes(): void
    {
        DB::unprepared('DROP TRIGGER service_projects_immutable');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->schema();
        try {
            $this->migrate();
            $this->fail('A noncontiguous surviving suffix must not be adopted.');
        } catch (LogicException) {
            $this->assertSame($before, $this->schema());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
        $this->resetOwned();
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'CREATE TRIGGER') && str_contains($query->sql, 'service_projects_immutable')) {
                $armed = false;
                throw new RuntimeException('Synthetic first guard interruption.');
            }
        });
        try {
            $this->migrate();
        } catch (RuntimeException) {
        }
        Schema::table('service_projects', fn ($table) => $table->string('unexpected')->nullable());
        $before = $this->schema();
        try {
            $this->migrate();
            $this->fail('Changed partial metadata must not be repaired.');
        } catch (LogicException) {
            $this->assertSame($before, $this->schema());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_incomplete_schema_with_original_rows_is_refused_and_preserved(): void
    {
        $f = F::setup();
        F::accept($f);
        DB::unprepared('DROP TRIGGER service_project_events_insert');
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $before = $this->schema();
        $rows = DB::table('service_project_events')->get()->toJson();
        try {
            $this->migrate();
            $this->fail('Incomplete protection cannot adopt retained evidence.');
        } catch (LogicException) {
            $this->assertSame($before, $this->schema());
            $this->assertSame($rows, DB::table('service_project_events')->get()->toJson());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_dependency_shadow_and_external_reference_are_refused_without_schema_or_log_changes(): void
    {
        $before = $this->schema();
        DB::unprepared(DB::getDriverName() === 'mysql' ? 'CREATE TEMPORARY TABLE users (id bigint unsigned not null auto_increment primary key)' : 'CREATE TEMP TABLE users (id integer primary key)');
        try {
            ServiceProjectSchema::install();
            $this->fail('Temporary authority dependency must not be used.');
        } catch (LogicException) {
            $this->assertSame($before, $this->schema());
        } finally {
            DB::unprepared('DROP TABLE '.(DB::getDriverName() === 'sqlite' ? 'temp.users' : 'users'));
        }
        Schema::create('synthetic_external_service_reference', fn ($table) => $table->foreignId('project_id')->constrained('service_projects'));
        $before = $this->schema();
        try {
            ServiceProjectSchema::install();
            $this->fail('External retained dependency needs inspection.');
        } catch (LogicException) {
            $this->assertSame($before, $this->schema());
            $this->assertDatabaseHas('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_partial_index_foreign_key_and_trigger_drift_and_reserved_guard_alias_are_refused_before_ddl(): void
    {
        $armed = false;
        DB::listen(function (QueryExecuted $query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'CREATE TRIGGER') && str_contains($query->sql, 'service_projects_immutable')) {
                $armed = false;
                throw new RuntimeException('Synthetic metadata probe interruption.');
            }
        });
        $probes = ['index', 'guard'];
        if (DB::getDriverName() === 'mysql') {
            $probes[] = 'foreign';
            $probes[] = 'invisible';
        }
        foreach ($probes as $probe) {
            $this->resetOwned();
            $armed = true;
            try {
                $this->migrate();
            } catch (RuntimeException) {
            }
            if ($probe === 'index') {
                Schema::table('service_projects', fn ($table) => $table->index('brief_hash', 'unexpected_service_index'));
            } elseif ($probe === 'foreign') {
                DB::unprepared('ALTER TABLE service_projects DROP FOREIGN KEY service_projects_created_by_foreign');
                DB::unprepared('ALTER TABLE service_projects ADD CONSTRAINT service_projects_created_by_foreign FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE CASCADE');
            } elseif ($probe === 'invisible') {
                DB::unprepared('ALTER TABLE service_projects ALTER INDEX service_projects_public INVISIBLE');
            } else {
                DB::unprepared('DROP TRIGGER service_projects_immutable');
                DB::unprepared(DB::getDriverName() === 'mysql'
                    ? "CREATE TRIGGER service_projects_immutable BEFORE UPDATE ON service_projects FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Changed guard body'; END"
                    : "CREATE TRIGGER service_projects_immutable BEFORE UPDATE ON service_projects BEGIN SELECT RAISE(ABORT, 'Changed guard body'); END");
            }
            $before = $this->schema();
            try {
                $this->migrate();
                $this->fail('Changed owned metadata must be refused before recovery writes.');
            } catch (LogicException) {
                $this->assertSame($before, $this->schema());
                $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
            }
        }
        $this->resetOwned();
        $alias = DB::getDriverName() === 'mysql' ? 'service_projects_immutablé' : 'SERVICE_PROJECTS_IMMUTABLE';
        DB::unprepared(DB::getDriverName() === 'mysql'
            ? 'CREATE TRIGGER `'.$alias.'` BEFORE UPDATE ON users FOR EACH ROW BEGIN SET @service_recovery_probe = 1; END'
            : 'CREATE TRIGGER "'.$alias.'" BEFORE UPDATE ON users BEGIN SELECT 1; END');
        try {
            $this->migrate();
            $this->fail('An external reserved guard alias must be found before any owned DDL.');
        } catch (LogicException) {
            $this->assertFalse(Schema::hasTable('service_projects'));
            $this->assertFalse(Schema::hasTable('service_project_events'));
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    public function test_sqlite_dependency_id_must_be_the_entire_primary_key_before_any_owned_ddl(): void
    {
        // This is a separate in-memory dependency graph, even when the main suite uses MySQL.
        $original = DB::getDefaultConnection();
        config(['database.connections.service_dependency_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::setDefaultConnection('service_dependency_probe');
        try {
            DB::unprepared('CREATE TABLE users (id INTEGER, tenant INTEGER, PRIMARY KEY (id, tenant))');
            DB::unprepared('CREATE TABLE customer_accounts (id INTEGER PRIMARY KEY)');
            DB::unprepared('CREATE TABLE service_draft_versions (id INTEGER PRIMARY KEY)');
            try {
                ServiceProjectSchema::install();
                $this->fail('The first component of a composite key is not a unique dependency identity.');
            } catch (LogicException) {
                $this->assertFalse(Schema::hasTable('service_projects'));
                $this->assertFalse(Schema::hasTable('service_project_events'));
                $this->assertSame(3, (int) DB::selectOne("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'table'")->n);
            }
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('service_dependency_probe');
        }
    }
}
