<?php

namespace Tests\Feature;

use App\Domain\Grants\Paid\PaidGrantSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/** Only synthetic isolated databases. Real Migrator faults preserve every surviving object/old ledger byte. */
final class PaidGrantSchemaRecoveryTest extends TestCase
{
    private const MIGRATION = '2026_10_07_252000_paid_grant_origins';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        if (DB::getDriverName() === 'mysql') {
            $this->assertSame('vaseyaudio_paid_grants', DB::getDatabaseName(), 'A dedicated synthetic paid-grant schema is required.');
        } else {
            $this->assertSame(':memory:', DB::getDatabaseName());
        }
        Schema::dropAllTables();
        foreach (['users', 'customer_accounts'] as $table) {
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
        return (new \ReflectionMethod(PaidGrantSchema::class, 'plan'))->invoke(null, DB::getDriverName(), DB::connection()->getTablePrefix())[0];
    }

    private function prior(): array
    {
        $result = [];
        foreach (['users', 'customer_accounts', 'migrations'] as $table) {
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
        $result['routines'] = $pdo->query('SELECT ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_DEFINITION,SQL_MODE,DEFINER,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=DATABASE() ORDER BY ROUTINE_NAME')->fetchAll(PDO::FETCH_ASSOC);
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
                if (in_array($table, ['guards', 'routines'], true)) {
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
                throw new RuntimeException('Committed paid-grant DDL interruption.');
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
                $this->assertSame('Committed paid-grant DDL interruption.', $e->getMessage());
            }
            $this->assertFalse($armed);
            $this->assertSame($prior, $this->prior());
            $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
            $survivors = $this->objects();
            $this->migrate();
            $this->assertSurvivors($survivors, $this->objects());
            if (DB::getDriverName() === 'mysql' && $index >= array_key_first(array_filter($steps, fn ($sql) => str_starts_with($sql, 'CREATE TRIGGER')))) {
                foreach ($survivors as $table => $definition) {
                    if (! in_array($table, ['guards', 'routines', 'migrations'], true)) {
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
        $row = $this->retainedBatch();
        DB::table('paid_order_origins')->insert($row);
        $old = DB::table('paid_order_origins')->get()->map(fn ($r) => (array) $r)->all();
        $prior = $this->prior();
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $objects = $this->objects();
        $this->migrate();
        $this->assertSame($old, DB::table('paid_order_origins')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($prior, $this->prior());
        $this->assertSurvivors($objects, $this->objects());
        $ledger = DB::table('migrations')->get()->map(fn ($r) => (array) $r)->all();
        try {
            app('migrator')->rollback([database_path('migrations/'.self::MIGRATION.'.php')], ['step' => 1]);
            $this->fail('Original evidence must survive rollback.');
        } catch (LogicException) {
            $this->assertSame($old, DB::table('paid_order_origins')->get()->map(fn ($r) => (array) $r)->all());
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
            'column' => Schema::table('paid_order_origins', fn (Blueprint $t) => $t->string('foreign_column')->nullable()),
            'guard' => $this->driftGuard(),
            'temporary' => DB::unprepared(DB::getDriverName() === 'sqlite' ? 'CREATE TEMP TABLE users (id INTEGER PRIMARY KEY)' : 'CREATE TEMPORARY TABLE users (id BIGINT UNSIGNED PRIMARY KEY)'),
            'external' => Schema::create('foreign_paid_dependency', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('paid_id');
                $t->foreign('paid_id')->references('id')->on('paid_order_origins');
            }),
            'partial-row' => DB::table('paid_order_origins')->insert($this->retainedBatch()),
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

    public function test_native_schema_global_exact_case_accent_fk_and_routine_identities_refuse_before_first_owned_ddl(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native schema-global identifier collision evidence.');
        }
        $this->assertStringStartsWith('8.0.46', DB::selectOne('SELECT VERSION() AS version')->version);
        foreach (['paid_grant_origins_batch', 'PAID_GRANT_ORIGINS_BATCH', 'páid_grant_origins_batch'] as $foreignName) {
            Schema::create('foreign_paid_marker', function (Blueprint $table) use ($foreignName): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('retained');
                $table->foreign('user_id', $foreignName)->references('id')->on('users')->restrictOnDelete();
            });
            DB::table('foreign_paid_marker')->insert(['user_id' => 1, 'retained' => 'EXPLICIT-FOREIGN-SYNTHETIC-ORIGINAL']);
            $this->assertRefusedBeforeOwnedDdl();
            Schema::drop('foreign_paid_marker');
        }
        $this->beforeApplicationDestroyed(fn () => DB::unprepared('DROP PROCEDURE IF EXISTS paid_order_origins'));
        DB::unprepared('CREATE PROCEDURE paid_order_origins() SELECT 7 AS retained_marker');
        $this->assertRefusedBeforeOwnedDdl();
    }

    private function assertRefusedBeforeOwnedDdl(): void
    {
        $objects = $this->objects();
        $rows = Schema::hasTable('foreign_paid_marker') ? DB::table('foreign_paid_marker')->get()->map(fn ($r) => (array) $r)->all() : [];
        $prior = $this->prior();
        try {
            $this->migrate();
            $this->fail('A reserved foreign identity must refuse before first owned DDL.');
        } catch (LogicException) {
            $this->assertSame($objects, $this->objects());
            $this->assertSame($prior, $this->prior());
            if ($rows !== []) {
                $this->assertSame($rows, DB::table('foreign_paid_marker')->get()->map(fn ($r) => (array) $r)->all());
            }
            foreach (array_keys(PaidGrantSchema::specs()) as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
        }
    }

    public function test_no_line_can_activate_until_all_first_originals_are_complete_and_immutable(): void
    {
        $this->migrate();
        $batch = $this->retainedBatch();
        $batch['line_count'] = 2;
        $batchId = DB::table('paid_order_origins')->insertGetId($batch);
        $fulfillment = ['batch_id' => $batchId, 'payload' => 'SYNTHETIC-COMPLETE-ORDER-BOUNDARY-ONLY', 'payload_hash' => str_repeat('d', 64), 'created_at' => '2026-10-07 00:01:00'];
        $this->refusedSql(fn () => DB::table('paid_fulfillments')->insert($fulfillment));
        $origins = [];
        foreach ([1, 2] as $position) {
            $letter = $position === 1 ? 'a' : 'b';
            $uuid = str_repeat($letter, 8).'-'.str_repeat($letter, 4).'-4'.str_repeat($letter, 3).'-8'.str_repeat($letter, 3).'-'.str_repeat($letter, 12);
            $origin = ['public_id' => $uuid, 'batch_id' => $batchId, 'position' => $position, 'line_public_id' => $uuid, 'line_hash' => str_repeat($letter, 64), 'source_hash' => str_repeat($letter, 64), 'payload' => 'SYNTHETIC-LINE-BOUNDARY-ONLY', 'payload_hash' => str_repeat($letter, 64), 'created_at' => '2026-10-07 00:00:00'];
            $invalid = $origin;
            $invalid['public_id'] = '-'.substr($uuid, 1);
            $this->refusedSql(fn () => DB::table('paid_grant_origins')->insert($invalid));
            $origins[$position] = DB::table('paid_grant_origins')->insertGetId($origin);
            DB::table('paid_document_work')->insert(['origin_id' => $origins[$position], 'state' => 'pending', 'attempts' => 0, 'created_at' => $origin['created_at']]);
        }
        foreach ($origins as $position => $originId) {
            $claim = $position === 1 ? 'cccccccc-cccc-4ccc-8ccc-cccccccccccc' : 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
            $query = fn () => DB::table('paid_document_work')->where('origin_id', $originId);
            $this->refusedSql(fn () => $query()->update(['state' => 'claimed', 'attempts' => 1, 'claim_id' => '-'.substr($claim, 1), 'expires_at' => '2026-10-07 00:05:00']));
            $query()->update(['state' => 'claimed', 'attempts' => 1, 'claim_id' => $claim, 'expires_at' => '2026-10-07 00:05:00']);
            $this->refusedSql(fn () => $query()->update(['state' => 'complete']));
            DB::table('paid_originals')->insert(['origin_id' => $originId, 'claim_id' => $claim, 'payload' => 'SYNTHETIC-FIRST-ORIGINAL-BOUNDARY-ONLY', 'payload_hash' => str_repeat('e', 64), 'created_at' => '2026-10-07 00:01:00']);
            $this->refusedSql(fn () => $query()->update(['state' => 'failed']));
            $query()->update(['state' => 'complete']);
            if ($position === 1) {
                $this->refusedSql(fn () => DB::table('paid_fulfillments')->insert($fulfillment));
            }
        }
        DB::table('paid_fulfillments')->insert($fulfillment);
        $originalRows = DB::table('paid_originals')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->refusedSql(fn () => DB::table('paid_originals')->where('id', $originalRows[0]['id'])->update(['payload' => 'REPLACEMENT']));
        $this->refusedSql(fn () => DB::table('paid_originals')->delete());
        $this->refusedSql(fn () => DB::table('paid_fulfillments')->insertOrIgnore($fulfillment));
        $this->assertSame($originalRows, DB::table('paid_originals')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame(1, DB::table('paid_fulfillments')->count());
        $this->assertSame(2, DB::table('paid_grant_origins')->count());
        // These are SQL boundary rows, not authenticated source, PDF, assets or a grant consumer.
    }

    private function refusedSql(\Closure $write): void
    {
        try {
            $write();
            $this->fail('An incomplete or replacement graph must refuse.');
        } catch (QueryException|PDOException) {
            $this->addToAssertionCount(1);
        }
    }

    private function retainedBatch(): array
    {
        return ['public_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'account_id' => 1, 'actor_id' => 1,
            'producer' => 'production_checkout_v1', 'order_public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'order_hash' => str_repeat('a', 64), 'payment_public_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'payment_hash' => str_repeat('b', 64), 'line_count' => 1,
            'payload' => 'SYNTHETIC-ENCRYPTED-ROW-BOUNDARY-ONLY', 'payload_hash' => str_repeat('c', 64), 'created_at' => '2026-10-07 00:00:00'];
    }

    private function driftGuard(): void
    {
        DB::unprepared('DROP TRIGGER paid_order_origins_immutable');
        DB::unprepared(DB::getDriverName() === 'sqlite'
            ? "CREATE TRIGGER paid_order_origins_immutable BEFORE UPDATE ON paid_order_origins BEGIN SELECT RAISE(ABORT, 'Foreign guard bytes'); END"
            : "CREATE TRIGGER paid_order_origins_immutable BEFORE UPDATE ON paid_order_origins FOR EACH ROW BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Foreign guard bytes'; END");
    }

    private function dropOwned(): void
    {
        $specs = (new \ReflectionMethod(PaidGrantSchema::class, 'specs'))->invoke(null);
        foreach (array_reverse(array_keys($specs)) as $table) {
            Schema::dropIfExists($table);
        }
    }
}
