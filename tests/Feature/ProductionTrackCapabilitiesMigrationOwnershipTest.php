<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPolicy\CapabilityHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductionTrackCapabilitiesMigrationOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // sqlite_master definitions and TEMP/writable-schema canaries are
        // intrinsically SQLite. Their isolated connection remains SQLite when
        // the surrounding suite selects MySQL; they are not native lock proof.
        $original = DB::getDefaultConnection();
        config(['database.connections.production_capability_ownership_fixture' => array_replace(config('database.connections.sqlite'),
            ['database' => ':memory:', 'url' => null])]);
        DB::setDefaultConnection('production_capability_ownership_fixture');
        Schema::clearResolvedInstance('db.schema');
        $this->beforeApplicationDestroyed(function () use ($original): void {
            DB::purge('production_capability_ownership_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        });
        $this->artisan('migrate:fresh', ['--database' => 'production_capability_ownership_fixture', '--force' => true])->assertExitCode(0);
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_236000_production_track_policy_capabilities.php');
    }

    private function metadata(): array
    {
        return [
            'main' => DB::table('sqlite_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all(),
            'temp' => DB::table('sqlite_temp_master')->orderBy('type')->orderBy('name')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }

    public function test_red_predecessor_foreign_trigger_on_absent_owned_schema_is_preserved_by_refusal(): void
    {
        $migration = $this->migration();
        $migration->down();
        DB::statement('CREATE TABLE independent_foreign_probe (id INTEGER)');
        DB::unprepared('CREATE TRIGGER ptc_candidate_insert BEFORE INSERT ON independent_foreign_probe BEGIN SELECT 1; END');
        $before = $this->metadata();
        try {
            $migration->down();
            $this->fail('Foreign fixed-name trigger was admitted for teardown.');
        } catch (LogicException) {
            $this->assertSame($before, $this->metadata());
            $this->assertSame('independent_foreign_probe', DB::table('sqlite_master')->where('name', 'ptc_candidate_insert')->value('tbl_name'));
            $this->assertTrue(Schema::hasTable('independent_foreign_probe'));
        }
    }

    public static function malformedInstallations(): array
    {
        return [
            ['missing owned guard'], ['changed owned guard'], ['uppercase owned guard'], ['foreign guard on owned table'],
            ['changed table columns'], ['changed table definition'], ['missing unique index'], ['changed unique index'], ['extra table index'],
            ['partial owned tables'], ['foreign key child'], ['external view'], ['external trigger'],
            ['temporary owned table'], ['temporary owned guard name'], ['temporary foreign key child'], ['temporary external view'],
            ['view under absent owned table name'], ['uppercase absent owned table name'], ['foreign index under absent owned index name'],
            ['foreign table under absent guard name'],
        ];
    }

    #[DataProvider('malformedInstallations')]
    public function test_every_unowned_modified_partial_shadow_or_external_dependency_is_preserved_before_ddl(string $scenario): void
    {
        $migration = $this->migration();
        $candidate = CapabilityHistory::CANDIDATES;
        if (str_contains($scenario, 'absent')) {
            $migration->down();
        }
        match ($scenario) {
            'missing owned guard' => DB::unprepared('DROP TRIGGER ptc_candidate_update'),
            'changed owned guard' => $this->replaceGuard('ptc_candidate_insert', 'CREATE TRIGGER ptc_candidate_insert BEFORE INSERT ON '.$candidate.' BEGIN SELECT 1; END'),
            'uppercase owned guard' => $this->replaceGuard('ptc_candidate_insert', 'CREATE TRIGGER PTC_CANDIDATE_INSERT BEFORE INSERT ON '.$candidate.' BEGIN SELECT 1; END'),
            'foreign guard on owned table' => DB::unprepared('CREATE TRIGGER independent_guard BEFORE INSERT ON '.$candidate.' BEGIN SELECT 1; END'),
            'changed table columns' => DB::statement('ALTER TABLE '.$candidate.' ADD COLUMN unrelated_flag INTEGER'),
            'changed table definition' => $this->alterTableDefinition($candidate),
            'missing unique index' => DB::statement('DROP INDEX ptc_candidate_public'),
            'changed unique index' => $this->replaceIndex('ptc_candidate_public', 'CREATE UNIQUE INDEX ptc_candidate_public ON '.$candidate.' (version_key)'),
            'extra table index' => DB::statement('CREATE INDEX independent_index ON '.$candidate.' (id)'),
            'partial owned tables' => DB::statement('DROP TABLE '.CapabilityHistory::CLOSURES),
            'foreign key child' => DB::statement('CREATE TABLE independent_child (id INTEGER, capability_id INTEGER REFERENCES '.$candidate.'(id))'),
            'external view' => DB::statement('CREATE VIEW independent_projection AS SELECT id FROM '.$candidate),
            'external trigger' => $this->externalTrigger(''),
            'temporary owned table' => DB::statement('CREATE TEMP TABLE '.$candidate.' (id INTEGER)'),
            'temporary owned guard name' => $this->temporaryGuard(),
            'temporary foreign key child' => DB::statement('CREATE TEMP TABLE independent_child (id INTEGER, capability_id INTEGER REFERENCES '.$candidate.'(id))'),
            'temporary external view' => DB::statement('CREATE TEMP VIEW independent_projection AS SELECT id FROM '.$candidate),
            'view under absent owned table name' => DB::statement('CREATE VIEW '.$candidate.' AS SELECT 1 AS id'),
            'uppercase absent owned table name' => DB::statement('CREATE TABLE '.strtoupper($candidate).' (id INTEGER)'),
            'foreign index under absent owned index name' => $this->absentForeignIndex(),
            'foreign table under absent guard name' => DB::statement('CREATE TABLE ptc_candidate_insert (id INTEGER)'),
        };
        $before = $this->metadata();
        $queries = [];
        $armed = true;
        DB::listen(function ($query) use (&$queries, &$armed): void {
            if ($armed && preg_match('/\A(?:drop|alter|create)\b/i', $query->sql) === 1) {
                $queries[] = $query->sql;
            }
        });
        try {
            $migration->down();
            $this->fail('Malformed or foreign installation was admitted for teardown.');
        } catch (LogicException) {
            $armed = false;
            $this->assertSame([], $queries, 'All admission checks must precede every teardown DDL.');
            $this->assertSame($before, $this->metadata());
        } finally {
            $armed = false;
        }
    }

    private function replaceGuard(string $name, string $statement): void
    {
        DB::unprepared('DROP TRIGGER '.$name);
        DB::unprepared($statement);
    }

    private function replaceIndex(string $name, string $statement): void
    {
        DB::statement('DROP INDEX '.$name);
        DB::statement($statement);
    }

    private function alterTableDefinition(string $table): void
    {
        // Simulated damaged restore changes the permanent definition while
        // preserving all column names and guard identities in disposable SQLite.
        DB::statement('PRAGMA writable_schema = ON');
        $sql = DB::table('sqlite_master')->where('name', $table)->value('sql');
        $changed = str_replace('"public_id" varchar not null', '"public_id" text not null', $sql);
        if ($changed === $sql) {
            throw new LogicException('Synthetic definition mutation did not change the expected column.');
        }
        DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->update(['sql' => $changed]);
        DB::statement('PRAGMA writable_schema = OFF');
    }

    private function externalTrigger(string $prefix): void
    {
        DB::statement('CREATE '.$prefix.'TABLE independent_trigger_source (id INTEGER)');
        DB::unprepared('CREATE '.$prefix.'TRIGGER independent_reference BEFORE INSERT ON independent_trigger_source BEGIN SELECT id FROM '.CapabilityHistory::CANDIDATES.'; END');
    }

    private function temporaryGuard(): void
    {
        DB::statement('CREATE TEMP TABLE independent_trigger_source (id INTEGER)');
        DB::unprepared('CREATE TEMP TRIGGER ptc_candidate_insert BEFORE INSERT ON independent_trigger_source BEGIN SELECT 1; END');
    }

    private function absentForeignIndex(): void
    {
        DB::statement('CREATE TABLE independent_index_source (id INTEGER)');
        DB::statement('CREATE INDEX ptc_candidate_public ON independent_index_source (id)');
    }

    public function test_exact_empty_owned_teardown_recreate_and_repeated_absent_noop_preserve_unrelated_schema(): void
    {
        DB::statement('CREATE TABLE independent_retained (id INTEGER)');
        DB::table('independent_retained')->insert(['id' => 47]);
        $migration = $this->migration();
        $migration->down();
        foreach ([CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, CapabilityHistory::CLOSURES] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $before = $this->metadata();
        $migration->down();
        $this->assertSame($before, $this->metadata());
        $this->assertSame(47, DB::table('independent_retained')->value('id'));
        $migration->up();
        foreach ([CapabilityHistory::CANDIDATES, CapabilityHistory::APPROVALS, CapabilityHistory::CLOSURES] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(9, DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'ptc_%')->count());
    }
}
