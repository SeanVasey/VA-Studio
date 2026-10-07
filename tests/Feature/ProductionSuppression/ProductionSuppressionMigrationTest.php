<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureSchema;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

class ProductionSuppressionMigrationTest extends TestCase
{
    public static function prefixes(): array
    {
        return array_map(fn ($prefix) => [$prefix], range(0, 16));
    }

    #[DataProvider('prefixes')]
    public function test_every_owned_prefix_resumes_without_adopting_legacy_or_253_history(int $prefix): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $customer = CustomerFixtures::account();
        ConsentFixtures::configure();
        (new CustomerConsentPreferences)->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw());
        $this->reset();
        $history = $this->history();
        foreach (array_slice($this->steps(), 0, $prefix) as [$type, $name, $sql]) {
            DB::unprepared($sql);
        }
        $schema = new ProductionSuppressionSchema;
        $schema->up();
        $schema->up();
        $this->assertSame($history, $this->history());
        foreach (ProductionSuppressionSchema::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'Installation must never backfill or adopt 251 or 253 history.');
        }
    }

    public function test_exact_complete_graph_revalidates_without_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->assertSame(1, DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->count());
        $ddl = $this->ddl();
        $schema = new ProductionSuppressionSchema;
        $schema->up();
        $schema->assertComplete(DB::connection()->getPdo(), DB::getDriverName(), DB::connection()->getDatabaseName());
        $schema->assertHeld(DB::connection()->getPdo(), DB::getDriverName(), DB::connection()->getDatabaseName());
        $this->assertSame([], $ddl());
    }

    public function test_lineage_references_only_253_bindings_and_production_withdrawal_events(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $references = [];
        foreach (ProductionSuppressionSchema::TABLES as $table) {
            $references[$table] = $this->references($table);
        }
        $this->assertSame([
            'production_suppression_targets' => ['binding_id' => 'production_account_feature_bindings', 'withdrawal_event_id' => 'production_consent_events'],
            'production_suppression_intents' => ['target_id' => 'production_suppression_targets', 'withdrawal_event_id' => 'production_consent_events'],
            'production_suppression_attempts' => ['intent_id' => 'production_suppression_intents', 'target_id' => 'production_suppression_targets'],
            'production_suppression_confirmations' => ['attempt_id' => 'production_suppression_attempts'],
        ], $references);
        $legacy = [...SuppressionSchema::TABLES, 'customer_consent_events', 'customer_consent_states', 'customer_consent_policies'];
        foreach ($references as $targets) {
            $this->assertSame([], array_intersect($targets, $legacy), '254 must never reference 251/250 lineage.');
            $this->assertSame([], array_diff($targets, [...ProductionSuppressionSchema::TABLES, ...ProductionSuppressionSchema::LINEAGE]));
        }
    }

    public function test_missing_253_guard_is_refused_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->reset();
        DB::unprepared('DROP TRIGGER production_consent_events_retain_update');
        $this->refusesBeforeDDL();
    }

    public function test_missing_identity_floor_is_refused_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->reset();
        DB::unprepared('DROP TRIGGER pi_verifications_insert');
        $this->refusesBeforeDDL();
    }

    public function test_foreign_reserved_key_and_cross_kind_table_name_refuse_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->reset();
        DB::unprepared('CREATE TABLE production_suppression_review_foreign (id integer)');
        DB::unprepared('CREATE INDEX production_suppression_attempts_intent_id_fk ON production_suppression_review_foreign(id)');
        $this->refusesBeforeDDL();
    }

    public function test_reserved_owned_table_name_on_a_foreign_trigger_refuses_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->reset();
        DB::unprepared('CREATE TABLE production_suppression_review_foreign (id integer)');
        DB::unprepared(DB::getDriverName() === 'sqlite' ? 'CREATE TRIGGER production_suppression_intents BEFORE INSERT ON production_suppression_review_foreign BEGIN SELECT 1; END' : 'CREATE TRIGGER production_suppression_intents BEFORE INSERT ON production_suppression_review_foreign FOR EACH ROW SET NEW.id=NEW.id');
        $this->refusesBeforeDDL();
    }

    public function test_recorded_gap_and_non_prefix_installation_refuse_without_repair(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::unprepared('DROP TRIGGER production_suppression_confirmations_retain_delete');
        $this->refusesBeforeDDL();
        $this->reset();
        DB::unprepared($this->steps()[1][2]);
        $this->refusesBeforeDDL();
    }

    public function test_drifted_owned_table_refuses_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->reset();
        DB::unprepared(str_replace('"withdrawal_event_id" integer not null', '"withdrawal_event_id" integer null', str_replace('`withdrawal_event_id` bigint unsigned NOT NULL', '`withdrawal_event_id` bigint unsigned NULL DEFAULT NULL', $this->steps()[0][2])));
        $this->refusesBeforeDDL();
    }

    public function test_real_migrator_can_resume_an_exact_unlogged_post_create_interruption(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->reset();
        $history = $this->history();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TABLE ') && str_contains($query->sql, 'production_suppression_intents')) {
                $fired = true;
                throw new \RuntimeException('Synthetic production suppression post-CREATE interruption');
            }
        });
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('The actual DDL callback must interrupt migration bookkeeping.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic production suppression post-CREATE interruption', $error->getMessage());
        }
        $this->assertTrue($fired);
        $this->assertSame(0, DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->count());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($history, $this->history());
        $this->assertSame(1, DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->count());
    }

    public function test_operational_down_refuses_before_queries_and_preserves_schema_and_record(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $before = $this->history();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            (new ProductionSuppressionSchema)->down();
            $this->fail('Operational destructive rollback must refuse.');
        } catch (LogicException) {
            $this->assertSame([], $queries);
        }
        $this->assertSame($before, $this->history());
        foreach (ProductionSuppressionSchema::TABLES as $table) {
            $this->assertTrue(DB::getSchemaBuilder()->hasTable($table));
        }
        $this->assertSame(1, DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->count());
    }

    private function references(string $table): array
    {
        if (DB::getDriverName() === 'sqlite') {
            $rows = DB::connection()->getPdo()->query('PRAGMA foreign_key_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
            $result = array_column($rows, 'table', 'from');
        } else {
            $statement = DB::connection()->getPdo()->prepare('SELECT COLUMN_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND REFERENCED_TABLE_NAME IS NOT NULL');
            $statement->execute([$table]);
            $result = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        ksort($result);

        return $result;
    }

    private function reset(): void
    {
        foreach (array_reverse(ProductionSuppressionSchema::TABLES) as $table) {
            $this->assertSame(0, DB::table($table)->count());
            DB::unprepared('DROP TABLE `'.$table.'`');
        }
        DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->delete();
    }

    private function steps(): array
    {
        $schema = new ProductionSuppressionSchema;
        $steps = [];
        foreach (ProductionSuppressionSchema::TABLES as $table) {
            $steps[] = ['table', $table, (new ReflectionMethod($schema, 'definition'))->invoke($schema, DB::getDriverName(), $table)];
        }
        foreach ((new ReflectionMethod($schema, 'triggers'))->invoke($schema, DB::getDriverName()) as $name => $guard) {
            $steps[] = ['trigger', $name, $guard['sql']];
        }

        return $steps;
    }

    private function ddl(): \Closure
    {
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });

        return function () use (&$ddl): array {
            return $ddl;
        };
    }

    private function refusesBeforeDDL(): void
    {
        $history = $this->history();
        $ddl = $this->ddl();
        try {
            (new ProductionSuppressionSchema)->up();
            $this->fail('Invalid ownership or dependency must refuse before DDL.');
        } catch (LogicException) {
            $this->assertSame([], $ddl());
        }
        $this->assertSame($history, $this->history());
    }

    private function history(): array
    {
        $result = [];
        foreach (['users', 'customer_accounts', 'customer_consent_events', 'customer_consent_states', ...SuppressionSchema::TABLES, ...ProductionFeatureSchema::TABLES] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
