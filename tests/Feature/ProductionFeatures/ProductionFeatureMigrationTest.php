<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureSchema;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

class ProductionFeatureMigrationTest extends TestCase
{
    public static function prefixes(): array
    {
        return array_map(fn ($prefix) => [$prefix], range(0, 20));
    }

    #[DataProvider('prefixes')]
    public function test_every_prefix_resumes_without_adopting_or_changing_private_legacy_history(int $prefix): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $customer = CustomerFixtures::account();
        ConsentFixtures::configure();
        (new ListeningLibrary)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC unbound legacy private playlist']);
        (new CustomerConsentPreferences)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        $this->resetProduction();
        $history = $this->history();
        foreach (array_slice($this->steps(), 0, $prefix) as [$type, $name, $sql]) {
            DB::unprepared($sql);
        }
        $schema = new ProductionFeatureSchema;
        $schema->up();
        $schema->up();
        $this->assertSame($history, $this->history());
        foreach (ProductionFeatureSchema::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count(), 'Installation must never backfill/adopt legacy feature data.');
        }
    }

    public function test_exact_complete_graph_can_be_revalidated_without_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        $schema = new ProductionFeatureSchema;
        $schema->up();
        $schema->assertComplete(DB::connection()->getPdo(), DB::connection()->getDriverName(), DB::connection()->getDatabaseName());
        $this->assertSame([], $ddl);
        foreach (ProductionFeatureSchema::TABLES as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }
    }

    public function test_private_runtime_admission_cannot_install_missing_storage(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::unprepared('DROP TRIGGER production_listening_libraries_retain_insert');
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        try {
            (new ProductionFeatureSchema)->assertComplete(DB::connection()->getPdo(), DB::connection()->getDriverName(), DB::connection()->getDatabaseName());
            $this->fail('Missing permanent guard must refuse.');
        } catch (LogicException) {
            $this->assertSame([], $ddl);
        }
    }

    public function test_foreign_reserved_key_and_cross_kind_table_name_refuse_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->resetProduction();
        DB::unprepared('CREATE TABLE production_feature_review_foreign (id integer)');
        DB::unprepared('CREATE INDEX production_consent_events_binding_id_fk ON production_feature_review_foreign(id)');
        $this->refusesBeforeDDL();
    }

    public function test_reserved_target_table_name_on_a_foreign_trigger_refuses_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->resetProduction();
        DB::unprepared('CREATE TABLE production_feature_review_foreign (id integer)');
        DB::unprepared(DB::getDriverName() === 'sqlite' ? 'CREATE TRIGGER production_consent_events BEFORE INSERT ON production_feature_review_foreign BEGIN SELECT 1; END' : 'CREATE TRIGGER production_consent_events BEFORE INSERT ON production_feature_review_foreign FOR EACH ROW SET NEW.id=NEW.id');
        $this->refusesBeforeDDL();
    }

    public function test_missing_identity_or_legacy_guard_is_refused_before_any_ddl(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->resetProduction();
        DB::unprepared('DROP TRIGGER pi_verifications_insert');
        $this->refusesBeforeDDL();
    }

    public function test_real_migrator_can_resume_an_exact_unlogged_post_create_interruption(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->resetProduction();
        $history = $this->history();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TABLE ') && str_contains($query->sql, 'production_account_feature_bindings')) {
                $fired = true;
                throw new \RuntimeException('Synthetic production feature post-CREATE interruption');
            }
        });
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('The actual DDL callback must interrupt migration bookkeeping.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic production feature post-CREATE interruption', $error->getMessage());
        }
        $this->assertTrue($fired);
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_253000_production_account_features')->count());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($history, $this->history());
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_07_253000_production_account_features')->count());
    }

    public function test_last_framework_ddl_callback_cannot_record_a_graph_with_an_earlier_guard_missing(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->resetProduction();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TRIGGER `production_consent_states_retain_delete`')) {
                $fired = true;
                DB::unprepared('DROP TRIGGER production_account_feature_bindings_retain_insert');
            }
        });
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('Final complete graph proof must refuse missing earlier guard.');
        } catch (LogicException) {
            $this->assertTrue($fired);
            $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_253000_production_account_features')->count());
        }
    }

    public function test_recorded_gap_and_non_prefix_installation_refuse_without_repair(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        DB::unprepared('DROP TRIGGER production_consent_states_retain_delete');
        $this->refusesBeforeDDL();
        $this->resetProduction();
        DB::unprepared($this->steps()[1][2]);
        $this->refusesBeforeDDL();
    }

    public function test_operational_down_refuses_before_queries_and_preserves_all_schema_and_history(): void
    {
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $before = $this->history();
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries) {
            $queries[] = $query->sql;
        });
        try {
            (new ProductionFeatureSchema)->down();
            $this->fail('Operational destructive rollback must refuse.');
        } catch (LogicException) {
            $this->assertSame([], $queries);
        }
        $this->assertSame($before, $this->history());
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_07_253000_production_account_features')->count());
    }

    private function resetProduction(): void
    {
        // The dependent 254 family references these tables; native MySQL refuses to drop a referenced parent.
        foreach (array_reverse(ProductionSuppressionSchema::TABLES) as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count());
                DB::unprepared('DROP TABLE `'.$table.'`');
            }
        }
        DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->delete();
        foreach (array_reverse(ProductionFeatureSchema::TABLES) as $table) {
            $this->assertSame(0, DB::table($table)->count());
            DB::unprepared('DROP TABLE `'.$table.'`');
        }
        DB::table('migrations')->where('migration', '2026_10_07_253000_production_account_features')->delete();
    }

    private function steps(): array
    {
        $schema = new ProductionFeatureSchema;
        $steps = [];
        foreach (ProductionFeatureSchema::TABLES as $table) {
            $steps[] = ['table', $table, (new ReflectionMethod($schema, 'definition'))->invoke($schema, DB::getDriverName(), $table)];
        }
        foreach ((new ReflectionMethod($schema, 'triggers'))->invoke($schema, DB::getDriverName()) as $name => $guard) {
            $steps[] = ['trigger', $name, $guard['sql']];
        }

        return $steps;
    }

    private function refusesBeforeDDL(): void
    {
        $history = $this->history();
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        try {
            (new ProductionFeatureSchema)->up();
            $this->fail('Invalid ownership must refuse before DDL.');
        } catch (LogicException) {
            $this->assertSame([], $ddl);
        }
        $this->assertSame($history, $this->history());
    }

    private function history(): array
    {
        $result = [];
        foreach (['users', 'customer_accounts', 'customer_saved_tracks', 'customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $result;
    }
}
