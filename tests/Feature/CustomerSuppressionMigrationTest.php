<?php

namespace Tests\Feature;

use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\MembershipFixtures;
use Tests\TestCase;

class CustomerSuppressionMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    #[DataProvider('prefixes')]
    public function test_every_known_installation_prefix_resumes_without_changing_prior_customer_history(int $prefix): void
    {
        $c = $prefix === 0 ? MembershipFixtures::bucket() : CustomerFixtures::account();
        ConsentFixtures::configure();
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::grant());
        (new ListeningLibrary)->change($c['principal'], $c['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC retained private playlist']);
        $this->resetSuppression();
        $before = $this->history();
        $schema = new SuppressionSchema;
        $steps = $this->steps();
        foreach (array_slice($steps, 0, $prefix) as [$type,$name,$sql]) {
            DB::unprepared($sql);
        }
        $schema->up();
        $schema->up();
        $this->assertSame($before, $this->history());
        $this->assertSame(16, count($this->objects()));
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw(1));
        $this->assertSame(1, DB::table('customer_suppression_intents')->count());
    }

    public static function prefixes(): array
    {
        return array_map(fn ($v) => [$v], range(0, 16));
    }

    public function test_real_migrator_retries_complete_unlogged_create_interruption(): void
    {
        CustomerFixtures::account();
        $this->resetSuppression();
        $before = $this->history();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TABLE ') && str_contains($query->sql, 'customer_suppression_targets')) {
                $fired = true;
                throw new \RuntimeException('Synthetic post-DDL interruption');
            }
        });
        try {
            $this->artisan('migrate', ['--force' => true]);
            $this->fail('Expected actual DDL interruption');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic post-DDL interruption', $e->getMessage());
        }
        $this->assertTrue($fired);
        $this->assertSame(1, count($this->objects()));
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->count());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame(16, count($this->objects()));
        $this->assertSame($before, $this->history());
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->count());
    }

    public function test_foreign_same_name_trigger_is_refused_before_any_owned_ddl(): void
    {
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE review_suppression_sentinel (id integer)');
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite' ? 'CREATE TRIGGER customer_suppression_targets BEFORE INSERT ON review_suppression_sentinel BEGIN SELECT 1; END' : 'CREATE TRIGGER customer_suppression_targets BEFORE INSERT ON review_suppression_sentinel FOR EACH ROW SET NEW.id=NEW.id');
        $before = $this->objects();
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame($before, $this->objects());
    }

    public function test_missing_or_drifted_consent_dependency_is_refused_before_any_owned_ddl(): void
    {
        $this->resetSuppression();
        DB::unprepared('DROP TRIGGER customer_consent_policies_retain_insert');
        $before = $this->objects();
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame($before, $this->objects());
    }

    public function test_missing_identity_dependency_and_disabled_fk_enforcement_are_refused_without_installing(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite-specific enforcement and missing-dependency combined fixture. Native missing dependency is separately reviewed.');
        }
        $this->resetSuppression();
        DB::unprepared('PRAGMA foreign_keys=OFF');
        DB::unprepared('DROP TABLE customer_accounts');
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame([], $this->objects());
        DB::unprepared('PRAGMA foreign_keys=ON');
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame([], $this->objects());
    }

    public function test_temporary_dependency_or_owned_alias_cannot_be_adopted(): void
    {
        $this->resetSuppression();
        DB::unprepared(DB::connection()->getDriverName() === 'sqlite' ? 'CREATE TEMP TABLE CUSTOMER_SUPPRESSION_TARGETS (id integer)' : 'CREATE TEMPORARY TABLE customer_suppression_targets (id integer)');
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame([], $this->objects());
    }

    public function test_non_prefix_or_recorded_missing_guard_is_refused_without_repair(): void
    {
        $this->resetSuppression();
        $steps = $this->steps();
        DB::unprepared($steps[1][2]);
        $before = $this->objects();
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame($before, $this->objects());
    }

    public function test_recorded_missing_guard_is_refused_without_silent_repair(): void
    {
        DB::unprepared('DROP TRIGGER customer_suppression_confirmations_retain_delete');
        $before = $this->objects();
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame($before, $this->objects());
        $this->assertSame(1, DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->count());
    }

    public function test_foreign_coexisting_name_or_last_ddl_extra_trigger_cannot_be_adopted(): void
    {
        $this->resetSuppression();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TRIGGER `customer_suppression_confirmations_retain_delete`')) {
                $fired = true;
                DB::unprepared(DB::connection()->getDriverName() === 'sqlite' ? 'CREATE TRIGGER suppression_foreign_guard BEFORE INSERT ON customer_suppression_targets BEGIN SELECT 1; END' : 'CREATE TRIGGER suppression_foreign_guard BEFORE INSERT ON customer_suppression_targets FOR EACH ROW SET NEW.purpose=NEW.purpose');
            }
        });
        $this->refuses(fn () => $this->artisan('migrate', ['--force' => true]));
        $this->assertTrue($fired);
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->count());
    }

    public function test_last_ddl_callback_cannot_record_a_schema_with_an_earlier_guard_removed(): void
    {
        $this->resetSuppression();
        $fired = false;
        DB::listen(function (QueryExecuted $query) use (&$fired) {
            if (! $fired && str_starts_with($query->sql, 'CREATE TRIGGER `customer_suppression_confirmations_retain_delete`')) {
                $fired = true;
                DB::unprepared('DROP TRIGGER customer_suppression_targets_retain_insert');
            }
        });
        $this->refuses(fn () => $this->artisan('migrate', ['--force' => true]));
        $this->assertTrue($fired);
        $this->assertSame(0, DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->count());
    }

    public function test_all_retained_rows_survive_refused_rollback_and_raw_update_delete_duplicate(): void
    {
        $c = CustomerFixtures::account();
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw());
        $before = DB::table('customer_suppression_targets')->get()->map(fn ($row) => (array) $row)->all();
        $this->refuses(fn () => (new SuppressionSchema)->down());
        foreach (['UPDATE customer_suppression_targets SET purpose=purpose', 'DELETE FROM customer_suppression_targets'] as $sql) {
            try {
                DB::unprepared($sql);
                $this->fail('Expected retained row guard');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
        }
        $r = $before[0];
        unset($r['id']);
        try {
            DB::table('customer_suppression_targets')->insertOrIgnore($r);
        } catch (QueryException) {
            $this->assertTrue(true);
        }
        $this->assertSame($before, DB::table('customer_suppression_targets')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(1, DB::table('customer_suppression_intents')->count());
    }

    public function test_sqlite_replace_cannot_delete_retained_target_or_intent(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('SQLite REPLACE delete bypass fixture; native retained update/delete/duplicate guards run separately.');
        }
        $c = CustomerFixtures::account();
        (new CustomerConsentPreferences)->change($c['principal'], $c['user'], ConsentFixtures::withdraw());
        foreach (['customer_suppression_targets', 'customer_suppression_intents'] as $table) {
            $row = (array) DB::table($table)->sole();
            $before = $row;
            $keys = implode(',', array_map(fn ($key) => '`'.$key.'`', array_keys($row)));
            $markers = implode(',', array_fill(0, count($row), '?'));
            try {
                DB::insert('INSERT OR REPLACE INTO `'.$table.'` ('.$keys.') VALUES ('.$markers.')', array_values($row));
                $this->fail('Expected retained REPLACE refusal');
            } catch (QueryException) {
                $this->assertTrue(true);
            }
            $this->assertSame($before, (array) DB::table($table)->sole());
        }
    }

    public function test_existing_foreign_index_and_reserved_guard_index_refuse_without_adoption(): void
    {
        DB::unprepared('CREATE INDEX suppression_foreign_index ON customer_suppression_targets (recipient_hmac)');
        $before = $this->objects();
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame($before, $this->objects());
        $this->resetSuppression();
        DB::unprepared('CREATE TABLE review_suppression_sentinel (id integer)');
        DB::unprepared('CREATE INDEX customer_suppression_targets_retain_insert ON review_suppression_sentinel (id)');
        $before = $this->objects();
        $this->refusesBeforeDDL(fn () => (new SuppressionSchema)->up());
        $this->assertSame($before, $this->objects());
    }

    private function resetSuppression(): void
    {
        foreach (array_reverse(SuppressionSchema::TABLES) as $table) {
            DB::unprepared('DROP TABLE `'.$table.'`');
        }
        DB::table('migrations')->where('migration', '2026_10_07_251000_customer_suppression')->delete();
    }

    private function steps(): array
    {
        $schema = new SuppressionSchema;
        $driver = DB::connection()->getDriverName();
        $steps = [];
        foreach (SuppressionSchema::TABLES as $table) {
            $steps[] = ['table', $table, (new ReflectionMethod($schema, 'definition'))->invoke($schema, $driver, $table)];
        }
        foreach ((new ReflectionMethod($schema, 'triggers'))->invoke($schema, $driver) as $name => $guard) {
            $steps[] = ['trigger', $name, $guard['sql']];
        }

        return $steps;
    }

    private function objects(): array
    {
        $pdo = DB::connection()->getPdo();
        if (DB::connection()->getDriverName() === 'sqlite') {
            return $pdo->query("SELECT type,name,sql FROM main.sqlite_master WHERE name LIKE 'customer_suppression_%' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        }
        $rows = [...$pdo->query("SELECT 'table' type,TABLE_NAME name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE 'customer_suppression_%'")->fetchAll(PDO::FETCH_ASSOC), ...$pdo->query("SELECT 'trigger' type,TRIGGER_NAME name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE 'customer_suppression_%'")->fetchAll(PDO::FETCH_ASSOC)];
        usort($rows, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $rows;
    }

    private function history(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy($table === 'quote_owners' ? 'owner_key' : 'id')->get()->map(fn ($r) => (array) $r)->all(), ['users', 'quote_owners', 'customer_accounts', 'customer_consent_policies', 'customer_consent_events', 'customer_consent_states', 'customer_saved_tracks', 'membership_credit_events']);
    }

    private function refusesBeforeDDL(callable $operation): void
    {
        $ddl = 0;
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl++;
            }
        });
        $this->refuses($operation);
        $this->assertSame(0, $ddl, 'Admission must refuse before its first DDL.');
    }

    private function refuses(callable $op): void
    {
        try {
            $op();
            $this->fail('Expected nondestructive migration refusal');
        } catch (LogicException) {
            $this->assertTrue(true);
        }
    }
}
