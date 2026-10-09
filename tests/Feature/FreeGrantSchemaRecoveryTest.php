<?php

namespace Tests\Feature;

use App\Domain\Grants\Free\FreeGrantSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DisposableNativeDatabase;
use Tests\TestCase;

/** Only synthetic isolated databases. Real Migrator faults preserve every surviving object/old ledger byte. */
final class FreeGrantSchemaRecoveryTest extends TestCase
{
    private const MIGRATION = '2026_10_07_245000_free_grant_origins';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertTrue(DisposableNativeDatabase::isAdmitted('vaseyaudio_free_grants'), 'A dedicated synthetic free-grant schema, or the CI job\'s disposable database, is required.');
        } else {
            $this->assertSame(':memory:', DB::getDatabaseName());
        }
        Schema::dropAllTables();
        foreach (['users', 'customer_accounts', 'license_versions', 'media_assets', 'tracks', 'rights_scopes'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('retained_source');
            });
            DB::table($table)->insert(['retained_source' => 'EXPLICIT-SYNTHETIC-OLD-'.$table]);
        }
        app('migration.repository')->createRepository();
        app('migration.repository')->log('1999_01_01_000000_retained_original', 1);
        $this->beforeApplicationDestroyed(fn () => Schema::dropAllTables());
    }

    private function migrate(): void
    {
        app('migrator')->run([database_path('migrations/'.self::MIGRATION.'.php')]);
    }

    private function plan(): array
    {
        return (new \ReflectionMethod(FreeGrantSchema::class, 'plan'))->invoke(null, DB::getDriverName(), DB::connection()->getTablePrefix())[0];
    }

    private function prior(): array
    {
        $result = [];
        foreach (['users', 'customer_accounts', 'license_versions', 'media_assets', 'tracks', 'rights_scopes', 'migrations'] as $table) {
            $query = DB::table($table)->orderBy('id');
            if ($table === 'migrations') {
                $query->where('migration', '!=', self::MIGRATION);
            }
            $result[$table] = $query->get()->map(fn ($r) => (array) $r)->all();
        }

        return $result;
    }

    private function objects(): array
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'sqlite') {
            return $pdo->query("SELECT type,name,tbl_name,sql FROM sqlite_master WHERE name NOT LIKE 'sqlite_%' ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        }
        $result = [];
        foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as [$table]) {
            $result[$table] = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        }
        $result['guards'] = $pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,EVENT_MANIPULATION,ACTION_STATEMENT,ACTION_ORDER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DEFINER,DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC);
        ksort($result);

        return $result;
    }

    private function assertSurvivors(array $before, array $after): void
    {
        if (DB::getDriverName() === 'sqlite') {
            foreach ($before as $row) {
                $this->assertContains($row, $after);
            }
        } else {
            foreach ($before as $table => $definition) {
                if ($table === 'guards') {
                    foreach ($definition as $guard) {
                        $this->assertContains($guard, $after['guards']);
                    }
                }
                // A newly appended migration row changes only its allocator value in SHOW CREATE.
                elseif ($table !== 'migrations') {
                    // CREATE TABLE can survive before its separately committed FK/index statements.
                    // Columns/storage and every explicit index/FK stay exact; InnoDB may replace
                    // an implicit FK index with a later authored unique whose leading key is the same.
                    $actualLines = array_map(fn ($line) => rtrim(trim($line), ','), explode("\n", $after[$table]));
                    foreach (explode("\n", $definition) as $line) {
                        $line = rtrim(trim($line), ',');
                        if (! in_array($line, $actualLines, true) && preg_match('/\AKEY `([^`]+)` \(`([^`]+)`\)\z/', $line, $match)) {
                            $replacement = array_filter($actualLines, fn ($new) => preg_match('/\AUNIQUE KEY `[^`]+` \(`'.preg_quote($match[2], '/').'`(?:,|\))/', $new));
                            $this->assertNotEmpty($replacement, 'Only InnoDB implicit-key replacement is permitted.');
                        } else {
                            $this->assertContains($line, $actualLines, 'A surviving native definition line must remain byte-exact.');
                        }
                    }
                }
            }
        }
    }

    public function test_every_sqlite_statement_and_selected_native_create_fk_index_guard_boundaries_retry_exactly(): void
    {
        $steps = $this->plan();
        $selected = DB::getDriverName() === 'sqlite' ? array_keys($steps) : array_values(array_unique([
            0,
            array_key_first(array_filter($steps, fn ($sql) => str_contains($sql, 'foreign key'))),
            array_key_first(array_filter($steps, fn ($sql) => str_contains($sql, 'add unique'))),
            array_key_first(array_filter($steps, fn ($sql) => str_starts_with($sql, 'CREATE TRIGGER'))),
            count($steps) - 1,
        ]));
        $prior = $this->prior();
        $target = null;
        $armed = false;
        DB::listen(function (QueryExecuted $query) use (&$target, &$armed): void {
            if ($armed && $query->sql === $target) {
                $armed = false;
                throw new RuntimeException('Committed free-grant DDL interruption.');
            }
        });
        foreach ($selected as $index) {
            $this->dropOwned();
            DB::table('migrations')->where('migration', self::MIGRATION)->delete();
            $target = $steps[$index];
            $armed = true;
            try {
                $this->migrate();
                $this->fail('Fault must interrupt the actual migrator.');
            } catch (RuntimeException $e) {
                $this->assertSame('Committed free-grant DDL interruption.', $e->getMessage());
            }
            $this->assertFalse($armed);
            $this->assertSame($prior, $this->prior());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
            $survivors = $this->objects();
            $this->migrate();
            $this->assertSurvivors($survivors, $this->objects());
            if (DB::getDriverName() === 'mysql' && $index >= array_key_first(array_filter($steps, fn ($sql) => str_starts_with($sql, 'CREATE TRIGGER')))) {
                foreach ($survivors as $table => $definition) {
                    if ($table !== 'guards' && $table !== 'migrations') {
                        $this->assertSame($definition, $this->objects()[$table]);
                    }
                }
            }
            $this->assertSame($prior, $this->prior());
            $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
            $completed = $this->objects();
            $this->migrate();
            $this->assertSame($completed, $this->objects());
        }
        $this->assertGreaterThan(20, count($steps));
    }

    public function test_completed_installation_with_retained_rows_survives_log_uncertainty_idempotently_and_refuses_down(): void
    {
        $this->migrate();
        $row = ['public_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'author_id' => 1, 'license_id' => 1, 'track_id' => 1, 'scope_id' => 1,
            'creation_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'request_hash' => str_repeat('a', 64), 'payload' => 'SYNTHETIC-ENCRYPTED-ROW-BOUNDARY-ONLY', 'payload_hash' => str_repeat('b', 64), 'created_at' => '2026-10-07 00:00:00'];
        DB::table('free_definitions')->insert($row);
        $old = DB::table('free_definitions')->get()->map(fn ($r) => (array) $r)->all();
        $prior = $this->prior();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $objects = $this->objects();
        $this->migrate();
        $this->assertSame($old, DB::table('free_definitions')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($prior, $this->prior());
        $this->assertSurvivors($objects, $this->objects());
        $ledger = DB::table('migrations')->get()->map(fn ($r) => (array) $r)->all();
        try {
            app('migrator')->rollback([database_path('migrations/'.self::MIGRATION.'.php')], ['step' => 1]);
            $this->fail('Original evidence must survive rollback.');
        } catch (LogicException) {
            $this->assertSame($old, DB::table('free_definitions')->get()->map(fn ($r) => (array) $r)->all());
            $this->assertSame($ledger, DB::table('migrations')->get()->map(fn ($r) => (array) $r)->all());
        }
    }

    public static function drifts(): array
    {
        return [['column'], ['guard'], ['partial-row'], ['temporary'], ['external']];
    }

    #[DataProvider('drifts')]
    public function test_foreign_metadata_partial_evidence_and_shadow_drift_refuse_before_any_retry_write(string $kind): void
    {
        $steps = $this->plan();
        $firstGuard = array_key_first(array_filter($steps, fn ($sql) => str_starts_with($sql, 'CREATE TRIGGER')));
        foreach (array_slice($steps, 0, $firstGuard + 1) as $sql) {
            DB::unprepared($sql);
        }
        match ($kind) {
            'column' => Schema::table('free_definitions', fn (Blueprint $t) => $t->string('foreign_column')->nullable()),
            'guard' => $this->driftGuard(),
            'temporary' => DB::unprepared(DB::getDriverName() === 'sqlite' ? 'CREATE TEMP TABLE users (id INTEGER PRIMARY KEY)' : 'CREATE TEMPORARY TABLE users (id BIGINT UNSIGNED PRIMARY KEY)'),
            'external' => Schema::create('foreign_free_dependency', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('free_id');
                $t->foreign('free_id')->references('id')->on('free_definitions');
            }),
            'partial-row' => DB::table('free_definitions')->insert(['public_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'author_id' => 1, 'license_id' => 1, 'track_id' => 1, 'scope_id' => 1, 'creation_key' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'request_hash' => str_repeat('a', 64), 'payload' => 'RETAIN-PARTIAL-ORIGINAL', 'payload_hash' => str_repeat('b', 64), 'created_at' => '2026-10-07 00:00:00']),
        };
        $objects = $this->objects();
        $prior = $this->prior();
        try {
            $this->migrate();
            $this->fail('Drift must refuse.');
        } catch (LogicException) {
            $this->assertSame($objects, $this->objects());
            $this->assertSame($prior, $this->prior());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
        if ($kind === 'temporary') {
            DB::unprepared('DROP TABLE users');
        }
    }

    public function test_sqlite_composite_dependency_primary_key_is_not_a_unique_id_target(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite dependency shape refusal.');
        }
        Schema::drop('users');
        DB::unprepared('CREATE TABLE users (id INTEGER NOT NULL, tenant INTEGER NOT NULL, PRIMARY KEY(id,tenant))');
        $before = $this->objects();
        try {
            $this->migrate();
            $this->fail('Composite first component cannot be an owned FK target.');
        } catch (LogicException) {
            $this->assertSame($before, $this->objects());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
        }
    }

    private function driftGuard(): void
    {
        DB::unprepared('DROP TRIGGER free_definitions_immutable');
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER free_definitions_immutable BEFORE UPDATE ON free_definitions BEGIN SELECT RAISE(ABORT, 'Foreign guard bytes'); END"
            : "CREATE TRIGGER free_definitions_immutable BEFORE UPDATE ON free_definitions FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Foreign guard bytes'; END");
    }

    private function dropOwned(): void
    {
        $specs = (new \ReflectionMethod(FreeGrantSchema::class, 'specs'))->invoke(null);
        foreach (array_reverse(array_keys($specs)) as $table) {
            Schema::dropIfExists($table);
        }
    }
}
