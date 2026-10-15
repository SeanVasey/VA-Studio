<?php

namespace Tests\Feature;

use App\Domain\Grants\Paid\PaidGrantSchema;
use App\Domain\Grants\PaidTax\PaidTaxDeliverySchema;
use App\Domain\Grants\PaidTax\PaidTaxSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Disposable SQL retention/recovery boundaries only; these rows are not authenticated paid grants. */
final class PaidTaxDeliverySchemaRecoveryTest extends TestCase
{
    private const MIGRATION = '2026_10_15_252200_paid_tax_delivery';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(DB::getDriverName() === 'mysql' ? getenv('DB_DATABASE') : ':memory:', DB::getDatabaseName());
        Schema::dropAllTables();
        foreach (['users', 'customer_accounts'] as $table) {
            Schema::create($table, function (Blueprint $t): void {
                $t->id();
                $t->string('retained_source');
            });
            DB::table($table)->insert(['retained_source' => 'EXPLICIT-SYNTHETIC-SQL-ONLY-'.$table]);
        }
        PaidGrantSchema::install();
        DB::table('paid_order_origins')->insert($this->retainedV1Boundary());
        PaidTaxSchema::install();
        DB::table('paid_tax_order_origins')->insert($this->retainedBatch());
        app('migration.repository')->createRepository();
        app('migration.repository')->log('1999_01_01_000000_retained_original', 1);
        $this->beforeApplicationDestroyed(fn () => Schema::dropAllTables());
    }

    private function prior(): array
    {
        $result = [];
        foreach (['users', 'customer_accounts', ...array_keys(PaidGrantSchema::specs()), ...array_keys(PaidTaxSchema::specs())] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $result;
    }

    private function migrate(): void
    {
        app('migrator')->run([database_path('migrations/'.self::MIGRATION.'.php')]);
    }

    private function plan(): array
    {
        return (new \ReflectionMethod(PaidTaxDeliverySchema::class, 'plan'))->invoke(null, DB::getDriverName(), DB::connection()->getTablePrefix())[0];
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
                        $this->assertContains($guard, $after[$table]);
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
        $this->assertGreaterThan(10, count($steps));
    }

    private function retainedBatch(): array
    {
        return ['public_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'account_id' => 1, 'actor_id' => 1,
            'producer' => 'production_tax_checkout_v2', 'order_public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'order_hash' => str_repeat('a', 64), 'request_public_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'request_hash' => str_repeat('b', 64), 'reviewed_session_public_id' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
            'reviewed_session_hash' => str_repeat('c', 64), 'provider_account' => 'acct_SYNTHETIC', 'funds_mode' => 'test',
            'provider_session_id' => 'cs_test_SQL_BOUNDARY', 'provider_payment_id' => 'pi_SQL_BOUNDARY', 'line_count' => 1,
            'payload' => 'SYNTHETIC-ENCRYPTED-ROW-BOUNDARY-ONLY', 'payload_hash' => str_repeat('d', 64), 'created_at' => '2026-10-15 00:00:00'];
    }

    private function retainedV1Boundary(): array
    {
        return ['public_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'account_id' => 1, 'actor_id' => 1,
            'producer' => 'production_checkout_v1', 'order_public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'order_hash' => str_repeat('a', 64), 'payment_public_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'payment_hash' => str_repeat('b', 64), 'line_count' => 1,
            'payload' => 'SYNTHETIC-ENCRYPTED-ROW-BOUNDARY-ONLY', 'payload_hash' => str_repeat('c', 64), 'created_at' => '2026-10-07 00:00:00'];
    }

    private function dropOwned(): void
    {
        $specs = (new \ReflectionMethod(PaidTaxDeliverySchema::class, 'specs'))->invoke(null);
        foreach (array_reverse(array_keys($specs)) as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function test_completed_populated_delivery_installation_is_idempotent_preserves_old_families_and_refuses_down(): void
    {
        $before = $this->prior();
        $this->migrate();
        $auth = ['public_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd', 'account_id' => 1, 'actor_id' => 1,
            'batch_public_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee', 'origin_public_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'origin_hash' => str_repeat('a', 64), 'request_key' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'request_hash' => str_repeat('b', 64), 'token_hash' => str_repeat('c', 64), 'target' => 'contract',
            'payload' => 'SYNTHETIC-SQL-BOUNDARY-ONLY', 'payload_hash' => str_repeat('d', 64), 'created_at' => '2026-10-15 00:00:00', 'expires_at' => '2026-10-15 00:02:00'];
        DB::table('paid_tax_authorizations')->insert($auth);
        DB::table('paid_tax_redemptions')->insert(['authorization_id' => 1, 'created_at' => '2026-10-15 00:00:30']);
        $objects = $this->objects();
        PaidTaxDeliverySchema::install();
        PaidTaxSchema::assertInstalled();
        $this->assertSame($before, $this->prior());
        $this->assertSame($objects, $this->objects());
        $this->assertDatabaseCount('paid_tax_authorizations', 1);
        $this->assertDatabaseCount('paid_tax_redemptions', 1);
        foreach (['paid_tax_authorizations', 'paid_tax_redemptions'] as $table) {
            try {
                DB::table($table)->where('id', 1)->delete();
                $this->fail('Retained delivery row was deleted.');
            } catch (QueryException $error) {
                $this->assertNotSame('', $error->getMessage());
            }
        }
        try {
            (require database_path('migrations/'.self::MIGRATION.'.php'))->down();
            $this->fail('Immutable delivery rollback was accepted.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Retain immutable Tax', $error->getMessage());
        }
    }

    public function test_temporary_owned_table_refuses_before_first_owned_ddl_and_preserves_populated_old_installations(): void
    {
        $before = $this->objects();
        DB::unprepared('CREATE TEMPORARY TABLE paid_tax_authorizations (id INTEGER)');
        $refused = false;
        try {
            $this->migrate();
        } catch (\Throwable $error) {
            $refused = true;
        } finally {
            DB::unprepared('DROP TABLE '.(DB::getDriverName() === 'sqlite' ? 'temp.paid_tax_authorizations' : 'paid_tax_authorizations'));
        }
        $this->assertTrue($refused);
        $this->assertSame($before, $this->objects(), 'Owned permanent DDL occurred before the temporary shadow was refused.');
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_changed_owned_guard_is_refused_without_repair_or_data_discard(): void
    {
        $this->migrate();
        DB::unprepared('DROP TRIGGER paid_tax_authorizations_immutable');
        $before = $this->objects();
        $rows = $this->prior();
        try {
            PaidTaxDeliverySchema::install();
            $this->fail('Incomplete changed guard was silently repaired.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('nothing was changed', $error->getMessage());
        }
        $this->assertSame($before, $this->objects());
        $this->assertSame($rows, $this->prior());
    }
}
