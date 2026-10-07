<?php

namespace Tests\Feature;

use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;
use Throwable;

class CustomerSuppressionKeyAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    public function test_exact_complete_owned_graph_still_revalidates(): void
    {
        $before = $this->snapshot();
        (new SuppressionSchema)->up();
        $this->assertSame($before, $this->snapshot());
    }

    #[DataProvider('reservedKeys')]
    public function test_every_derived_reserved_key_on_a_foreign_index_refuses_before_ddl(string $name): void
    {
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE suppression_review_foreign_marker (id integer)');
        DB::unprepared('CREATE INDEX `'.$name.'` ON suppression_review_foreign_marker (id)');
        $this->refusesBeforeDDL();
    }

    public static function reservedKeys(): array
    {
        $schema = new SuppressionSchema;
        $names = [];
        foreach (SuppressionSchema::TABLES as $table) {
            foreach (array_keys((new ReflectionMethod($schema, 'indexes'))->invoke($schema, $table)) as $name) {
                $names[$name] = [$name];
            }
            foreach (array_keys((new ReflectionMethod($schema, 'foreign'))->invoke($schema, $table)) as $column) {
                $name = $table.'_'.$column.'_fk';
                $names[$name] = [$name];
            }
        }

        return $names;
    }

    public function test_foreign_reserved_native_foreign_key_refuses_before_owned_ddl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual native schema-wide foreign-key namespace collision.');
        }
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE suppression_review_foreign_marker (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NULL, CONSTRAINT customer_suppression_intents_consent_event_id_fk FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB');
        DB::table('suppression_review_foreign_marker')->insert(['id' => 9123, 'user_id' => null]);
        $this->refusesBeforeDDL();
        $this->assertSame([['id' => 9123, 'user_id' => null]], DB::connection()->getPdo()->query('SELECT id,user_id FROM suppression_review_foreign_marker')->fetchAll(PDO::FETCH_ASSOC));
    }

    public function test_native_dictionary_collation_alias_of_reserved_constraint_refuses_before_ddl(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual native dictionary collation alias.');
        }
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE suppression_review_foreign_marker (id BIGINT UNSIGNED PRIMARY KEY, user_id BIGINT UNSIGNED NULL, CONSTRAINT CUSTOMER_SUPPRESSION_INTENTS_CONSENT_EVENT_ID_FK FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB');
        $statement = DB::connection()->getPdo()->prepare('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME=?');
        $statement->execute(['customer_suppression_intents_consent_event_id_fk']);
        $this->assertSame(['CUSTOMER_SUPPRESSION_INTENTS_CONSENT_EVENT_ID_FK'], $statement->fetchAll(PDO::FETCH_COLUMN), 'Fixture must actually match the dictionary collation.');
        $this->refusesBeforeDDL();
    }

    public function test_reserved_key_used_by_another_object_kind_refuses_before_ddl(): void
    {
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE customer_suppression_targets_recipient_unique (id integer)');
        $this->refusesBeforeDDL();
    }

    public function test_temporary_reserved_key_refuses_before_ddl(): void
    {
        $this->resetSuppression();
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite' ? 'CREATE TEMP TABLE customer_suppression_targets_recipient_unique (id integer)' : 'CREATE TEMPORARY TABLE customer_suppression_targets_recipient_unique (id integer)');
        $this->refusesBeforeDDL();
    }

    public function test_final_ddl_callback_cannot_record_a_late_foreign_reserved_index(): void
    {
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE suppression_review_foreign_marker (id integer)');
        DB::table('suppression_review_foreign_marker')->insert(['id' => 9123]);
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TRIGGER `customer_suppression_confirmations_retain_delete`')) {
                $fired = true;
                DB::unprepared('CREATE INDEX customer_suppression_targets_recipient_unique ON suppression_review_foreign_marker (id)');
            }
        });
        $error = null;
        try {
            $this->artisan('migrate', ['--force' => true]);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $this->assertTrue($fired);
        $this->assertInstanceOf(LogicException::class, $error);
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->count());
        $this->assertSame([['id' => 9123]], DB::table('suppression_review_foreign_marker')->get()->map(fn ($row) => (array) $row)->all());
    }

    private function resetSuppression(): void
    {
        foreach (array_reverse(SuppressionSchema::TABLES) as $table) {
            $this->assertSame(0, DB::table($table)->count());
            DB::unprepared('DROP TABLE `'.$table.'`');
        }
        DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->delete();
    }

    private function refusesBeforeDDL(): void
    {
        $before = $this->snapshot();
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        $error = null;
        try {
            (new SuppressionSchema)->up();
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $this->assertInstanceOf(LogicException::class, $error);
        $this->assertSame([], $ddl);
        $this->assertSame($before, $this->snapshot());
    }

    private function snapshot(): array
    {
        $pdo = DB::connection()->getPdo();
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $pdo->query('SELECT type,name,tbl_name,sql FROM main.sqlite_master ORDER BY type,name')->fetchAll(PDO::FETCH_ASSOC);
        }

        return [
            $pdo->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,CONSTRAINT_NAME')->fetchAll(PDO::FETCH_ASSOC),
            $pdo->query('SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME,SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
}
