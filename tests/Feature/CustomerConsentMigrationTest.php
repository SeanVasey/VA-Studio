<?php

namespace Tests\Feature;

use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Preferences\ConsentPolicy;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Models\ConsentEvent;
use App\Domain\Customers\Preferences\Models\ConsentPolicySnapshot;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\CapabilityRollbackFixture;
use Tests\Support\ConsentFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures;
use Tests\TestCase;

class CustomerConsentMigrationTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_250000_customer_consent';

    private const TABLES = ['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'];

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }

    private function history(): array
    {
        $pdo = DB::connection()->getPdo();

        return array_map(fn ($table) => $pdo->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC), ['customer_accounts', 'membership_credit_events', 'customer_saved_tracks']);
    }

    public static function interruptions(): array
    {
        return array_combine(array_map(fn ($n) => 'after DDL step '.$n, range(1, 12)), array_map(fn ($n) => [$n], range(1, 12)));
    }

    #[DataProvider('interruptions')]
    public function test_actual_migrator_restarts_every_owned_table_trigger_prefix_and_preserves_history(int $step): void
    {
        $customer = MembershipFixtures::bucket();
        app(ListeningLibrary::class)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC retained preferences']);
        $before = $this->history();
        // Later additive migrations reference the owned tables (customer_suppression_intents keys on
        // customer_consent_events), and MySQL refuses to drop a referenced parent. Dispose those empty
        // dependents leaves-first, derived from the live catalog, with foreign-key enforcement unchanged.
        $dependents = CapabilityRollbackFixture::dependents(self::TABLES);
        $this->assertContains('customer_suppression_intents', $dependents);
        CapabilityRollbackFixture::dropEmptyLeavesFirst($dependents);
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::drop($table); // These own empty tables are disposable isolated test fixtures.
        }
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        $seen = 0;
        $fired = false;
        DB::listen(function ($query) use (&$seen, &$fired, $step): void {
            if (! $fired && preg_match('/\ACREATE (?:TABLE|TRIGGER)\b.*customer_consent_/is', $query->sql) && ++$seen === $step) {
                $fired = true;
                throw new RuntimeException('SYNTHETIC interrupted consent DDL.');
            }
        });
        try {
            $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true])->run();
            $this->fail('The exact post-DDL interruption was not propagated.');
        } catch (RuntimeException $error) {
            $this->assertSame('SYNTHETIC interrupted consent DDL.', $error->getMessage());
        }
        $this->assertTrue($fired);
        $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        // Retained exact original data in an owned prefix must not be erased during repair.
        $policy = app(ConsentPolicy::class);
        ConsentFixtures::configure();
        ConsentPolicySnapshot::create($policy->configured() + ['created_at' => now()->utc()->format('Y-m-d H:i:s')]);
        $retained = ConsentPolicySnapshot::sole()->getRawOriginal();
        $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->history());
        $this->assertSame($retained, ConsentPolicySnapshot::sole()->getRawOriginal());
        $this->assertSame(1, DB::table('migrations')->where('migration', self::MIGRATION)->count());
        $this->assertDatabaseCount('customer_consent_events', 0);
        $this->assertDatabaseCount('customer_consent_states', 0);
        $this->assertSame('granted', app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant())['purposes'][0]['status']);
        $this->migration()->up();
        $this->assertSame($before, $this->history());
    }

    public function test_operational_rollback_refuses_before_queries_and_keeps_policy_choice_history_and_repository_record(): void
    {
        $customer = MembershipFixtures::bucket();
        ConsentFixtures::configure();
        app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        $before = array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), [...self::TABLES, 'migrations']);
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        try {
            $this->migration()->down();
            $this->fail('Retained consent rollback accepted.');
        } catch (LogicException) {
            $this->assertSame(0, $queries);
        }
        try {
            $this->artisan('migrate:rollback', ['--step' => 1])->run();
            $this->fail('Repository rollback accepted.');
        } catch (LogicException) {
            $this->assertSame($before, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(), [...self::TABLES, 'migrations']));
        }
    }

    public static function drift(): array
    {
        return ['extra index' => ['index'], 'extra column' => ['column'], 'unexpected trigger' => ['trigger'], 'missing prefix trigger' => ['gap'], 'missing final recorded trigger' => ['tail-gap'], 'temporary shadow' => ['shadow']];
    }

    #[DataProvider('drift')]
    public function test_foreign_or_drifted_storage_refuses_without_adopting_or_dropping_any_object(string $drift): void
    {
        MembershipFixtures::bucket();
        $before = $this->history();
        $driver = DB::getDriverName();
        match ($drift) {
            'index' => DB::unprepared('CREATE INDEX consent_foreign_index ON customer_consent_policies (notice_hash)'),
            'column' => DB::unprepared('ALTER TABLE customer_consent_policies ADD COLUMN foreign_sentinel TEXT'),
            'trigger' => DB::unprepared($driver === 'sqlite' ? "CREATE TRIGGER consent_foreign_trigger BEFORE DELETE ON customer_consent_policies BEGIN SELECT RAISE(ABORT, 'SYNTHETIC foreign'); END" : "CREATE TRIGGER consent_foreign_trigger BEFORE DELETE ON customer_consent_policies FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='SYNTHETIC foreign'"),
            'gap' => DB::unprepared('DROP TRIGGER customer_consent_policies_retain_update'),
            'tail-gap' => DB::unprepared('DROP TRIGGER customer_consent_states_retain_delete'),
            'shadow' => DB::unprepared('CREATE TEMPORARY TABLE customer_consent_policies (private_sentinel TEXT)'),
        };
        try {
            foreach (['up', 'down'] as $method) {
                try {
                    $this->migration()->$method();
                    $this->fail('Foreign/drifted retained schema was accepted.');
                } catch (LogicException) {
                    $this->assertSame($before, $this->history());
                }
            }
            if ($drift === 'shadow') {
                DB::table('customer_consent_policies')->insert(['private_sentinel' => 'SYNTHETIC private sentinel']);
                $this->assertSame('SYNTHETIC private sentinel', DB::table('customer_consent_policies')->value('private_sentinel'));
            } else {
                $this->assertTrue(Schema::hasTable('customer_consent_policies'));
            }
        } finally {
            if ($drift === 'shadow') {
                DB::unprepared($driver === 'sqlite' ? 'DROP TABLE temp.customer_consent_policies' : 'DROP TEMPORARY TABLE customer_consent_policies');
            }
        }
    }

    public function test_direct_sql_cannot_rewrite_delete_replace_or_rebind_retained_graph(): void
    {
        $customer = MembershipFixtures::bucket();
        ConsentFixtures::configure();
        app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        $event = ConsentEvent::sole();
        $attempts = [fn () => DB::table('customer_consent_policies')->update(['notice' => 'SYNTHETIC replaced']),
            fn () => DB::table('customer_consent_events')->delete(),
            fn () => DB::table('customer_consent_states')->delete(),
            fn () => DB::table('customer_consent_states')->update(['revision' => 2]),
            fn () => DB::statement((DB::getDriverName() === 'sqlite' ? 'INSERT OR REPLACE' : 'REPLACE').' INTO customer_consent_events ('.implode(',', array_keys($event->getRawOriginal())).') VALUES ('.implode(',', array_fill(0, count($event->getRawOriginal()), '?')).')', array_values($event->getRawOriginal()))];
        foreach ($attempts as $attempt) {
            try {
                $attempt();
                $this->fail('A retained graph mutation escaped its database guard.');
            } catch (QueryException) {
                $this->assertSame('granted', app(CustomerConsentPreferences::class)->read($customer['principal'], $customer['user'])['purposes'][0]['status']);
                $this->assertSame(1, ConsentEvent::count());
            }
        }
    }
}
