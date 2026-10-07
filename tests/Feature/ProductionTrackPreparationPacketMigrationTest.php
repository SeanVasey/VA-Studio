<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionPreparation\PacketEvidence;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\Support\ProductionTrackPreparationFixtures as Fixture;
use Tests\TestCase;

class ProductionTrackPreparationPacketMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Exact sqlite_master/TEMP/replacement ownership canaries always use this
        // disposable SQLite connection, including under an outer MySQL selection.
        $original = DB::getDefaultConnection();
        config(['database.connections.production_packet_migration_fixture' => array_replace(config('database.connections.sqlite'), ['database' => ':memory:', 'url' => null]),
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32))]);
        DB::setDefaultConnection('production_packet_migration_fixture');
        Schema::clearResolvedInstance('db.schema');
        $this->beforeApplicationDestroyed(function () use ($original): void {
            DB::purge('production_packet_migration_fixture');
            DB::setDefaultConnection($original);
            Schema::clearResolvedInstance('db.schema');
        });
        $this->artisan('migrate:fresh', ['--database' => 'production_packet_migration_fixture', '--force' => true])->assertExitCode(0);
        $this->assertDatabaseCount('production_buyer_assent_observations', 0);
        Schema::drop('production_buyer_assent_observations');
        $this->fakePrivateMediaStorage();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_10_06_238000_production_track_preparation_packets.php');
    }

    public function test_sqlite_and_mysql_grammars_compile_exact_short_parent_identities_without_native_contact(): void
    {
        $sqlite = DB::getDefaultConnection();
        $contacts = 0;
        $mysql = new MySqlConnection(function () use (&$contacts): never {
            $contacts++;
            throw new LogicException('Grammar proof must never contact MySQL.');
        }, 'synthetic_compile_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        $mysql->useDefaultSchemaGrammar();
        DB::extend('production_packet_mysql_grammar', fn () => $mysql);
        config(['database.connections.production_packet_mysql_grammar' => ['driver' => 'production_packet_mysql_grammar']]);
        try {
            foreach ([$sqlite, 'production_packet_mysql_grammar'] as $name) {
                DB::setDefaultConnection($name);
                $connection = DB::connection();
                $migration = $this->migration();
                $definitions = (new ReflectionMethod($migration, 'definitions'))->invoke($migration);
                $statements = [];
                foreach ($definitions as $table => $definition) {
                    $blueprint = new Blueprint($connection, $table);
                    $blueprint->create();
                    $definition($blueprint);
                    $statements = [...$statements, ...$blueprint->toSql()];
                    $foreign = [];
                    foreach ($blueprint->getCommands() as $command) {
                        if ($command->name === 'foreign') {
                            $this->assertLessThanOrEqual(64, strlen($command->index));
                            $foreign[$command->index] = [$command->columns, $command->on, (array) $command->references, $command->onDelete];
                        }
                    }
                    if ($table === PacketEvidence::LINES) {
                        $this->assertSame([
                            'ptp_line_parent' => [['production_track_preparation_packet_id'], PacketEvidence::PACKETS, ['id'], 'restrict'],
                            'ptp_line_track_parent' => [['track_id'], 'tracks', ['id'], 'restrict'],
                            'ptp_line_offer_parent' => [['offer_id'], 'offers', ['id'], 'restrict'],
                            'ptp_line_revision_parent' => [['offer_revision_id'], 'offer_revisions', ['id'], 'restrict'],
                            'ptp_line_license_parent' => [['license_version_id'], 'license_versions', ['id'], 'restrict'],
                        ], $foreign);
                    }
                }
                $guards = (new ReflectionMethod($migration, 'guards'))->invoke($migration);
                foreach ($guards as $guardName => $definition) {
                    $this->assertLessThanOrEqual(64, strlen($guardName));
                    $statements[] = $definition['statement'];
                }
                preg_match_all('/["`]([^"`]+)["`]/', implode("\n", $statements), $identities);
                foreach (array_unique($identities[1]) as $identity) {
                    $this->assertLessThanOrEqual(64, strlen($identity));
                }
                if ($connection->getDriverName() === 'mysql') {
                    $this->assertStringContainsString('`ptp_line_revision_parent`', implode("\n", $statements));
                    $this->assertStringContainsString('`ptp_line_license_parent`', implode("\n", $statements));
                    $this->assertStringContainsString("SIGNAL SQLSTATE '45000'", implode("\n", $statements));
                }
            }
            $this->assertSame(0, $contacts);
        } finally {
            DB::setDefaultConnection($sqlite);
            DB::purge('production_packet_mysql_grammar');
        }
    }

    private function metadata(): array
    {
        return ['main' => DB::table('sqlite_master')->orderBy('type')->orderBy('name')->get()->map(fn ($r): array => (array) $r)->all(),
            'temp' => DB::table('sqlite_temp_master')->orderBy('type')->orderBy('name')->get()->map(fn ($r): array => (array) $r)->all()];
    }

    public function test_exact_empty_roundtrip_clean_absent_noop_and_reinstallation_preserve_unrelated_schema(): void
    {
        $before = $this->metadata();
        $migration = $this->migration();
        $migration->down();
        $empty = $this->metadata();
        $migration->down();
        $this->assertSame($empty, $this->metadata());
        $migration->up();
        $this->assertSame($before, $this->metadata());
        try {
            $migration->up();
            $this->fail('Existing owned schema reinstalled.');
        } catch (LogicException) {
            $this->assertSame($before, $this->metadata());
        }
    }

    public function test_retained_packet_refuses_teardown_before_any_guard_table_or_parent_change(): void
    {
        Fixture::prepared(true);
        $before = $this->metadata();
        $rows = Fixture::rows();
        try {
            $this->migration()->down();
            $this->fail('Retained evidence dropped.');
        } catch (RuntimeException) {
            $this->assertSame($before, $this->metadata());
            $this->assertSame($rows, Fixture::rows());
        }
    }

    public static function foreignOrModified(): array
    {
        return [['foreign trigger absent'], ['foreign named table absent'], ['foreign index absent'], ['aliased table absent'], ['view absent'],
            ['missing guard'], ['modified guard'], ['extra guard'], ['missing index'], ['modified index'], ['extra column'], ['partial tables'],
            ['temp table'], ['temp guard'], ['external FK'], ['external view'], ['external trigger'], ['temp FK'], ['temp view']];
    }

    #[DataProvider('foreignOrModified')]
    public function test_foreign_modified_partial_temporary_and_external_objects_refuse_all_rollback_ddl(string $scenario): void
    {
        $migration = $this->migration();
        $table = PacketEvidence::PACKETS;
        if (str_contains($scenario, 'absent')) {
            $migration->down();
        }
        match ($scenario) {
            'foreign trigger absent' => $this->foreignTrigger('ptp_packet_insert', ''),
            'foreign named table absent' => DB::statement('CREATE TABLE ptp_packet_insert (id INTEGER)'),
            'foreign index absent' => $this->foreignIndex(),
            'aliased table absent' => DB::statement('CREATE TABLE '.strtoupper($table).' (id INTEGER)'),
            'view absent' => DB::statement('CREATE VIEW '.$table.' AS SELECT 1 AS id'),
            'missing guard' => DB::unprepared('DROP TRIGGER ptp_line_delete'),
            'modified guard' => $this->changedGuard(),
            'extra guard' => DB::unprepared('CREATE TRIGGER synthetic_extra BEFORE INSERT ON '.$table.' BEGIN SELECT 1; END'),
            'missing index' => DB::statement('DROP INDEX ptp_public'),
            'modified index' => $this->changedIndex(),
            'extra column' => DB::statement('ALTER TABLE '.$table.' ADD COLUMN synthetic_extra INTEGER'),
            'partial tables' => DB::statement('DROP TABLE '.PacketEvidence::LINES),
            'temp table' => DB::statement('CREATE TEMP TABLE '.$table.' (id INTEGER)'),
            'temp guard' => $this->foreignTrigger('ptp_packet_insert', 'TEMP'),
            'external FK' => DB::statement('CREATE TABLE synthetic_child (id INTEGER, packet_id INTEGER REFERENCES '.$table.'(id))'),
            'external view' => DB::statement('CREATE VIEW synthetic_view AS SELECT id FROM '.$table),
            'external trigger' => $this->externalTrigger(),
            'temp FK' => DB::statement('CREATE TEMP TABLE synthetic_child (id INTEGER, packet_id INTEGER REFERENCES '.$table.'(id))'),
            'temp view' => DB::statement('CREATE TEMP VIEW synthetic_view AS SELECT id FROM '.$table),
        };
        $before = $this->metadata();
        $ddl = [];
        $armed = true;
        DB::listen(function ($query) use (&$ddl, &$armed): void {
            if ($armed && preg_match('/\A(?:drop|alter|create)\b/i', $query->sql) === 1) {
                $ddl[] = $query->sql;
            }
        });
        try {
            $migration->down();
            $this->fail('Unowned or changed schema dropped.');
        } catch (LogicException) {
            $armed = false;
            $this->assertSame([], $ddl);
            $this->assertSame($before, $this->metadata());
        } finally {
            $armed = false;
        }
    }

    public function test_foreign_guard_on_absent_schema_also_blocks_installation_without_replacing_it(): void
    {
        $migration = $this->migration();
        $migration->down();
        $this->foreignTrigger('ptp_packet_insert', '');
        $before = $this->metadata();
        try {
            $migration->up();
            $this->fail('Foreign object replaced by installation.');
        } catch (LogicException) {
            $this->assertSame($before, $this->metadata());
        }
    }

    private function foreignTrigger(string $name, string $scope): void
    {
        DB::statement('CREATE '.$scope.' TABLE synthetic_foreign (id INTEGER)');
        DB::unprepared('CREATE '.$scope.' TRIGGER '.$name.' BEFORE INSERT ON synthetic_foreign BEGIN SELECT 1; END');
    }

    private function foreignIndex(): void
    {
        DB::statement('CREATE TABLE synthetic_foreign (id INTEGER)');
        DB::statement('CREATE INDEX ptp_public ON synthetic_foreign (id)');
    }

    private function changedGuard(): void
    {
        DB::unprepared('DROP TRIGGER ptp_packet_insert');
        DB::unprepared('CREATE TRIGGER ptp_packet_insert BEFORE INSERT ON '.PacketEvidence::PACKETS.' BEGIN SELECT 1; END');
    }

    private function changedIndex(): void
    {
        DB::statement('DROP INDEX ptp_public');
        DB::statement('CREATE UNIQUE INDEX ptp_public ON '.PacketEvidence::PACKETS.' (request_hash)');
    }

    private function externalTrigger(): void
    {
        DB::statement('CREATE TABLE synthetic_foreign (id INTEGER)');
        DB::unprepared('CREATE TRIGGER synthetic_external BEFORE INSERT ON synthetic_foreign BEGIN SELECT id FROM '.PacketEvidence::PACKETS.'; END');
    }
}
