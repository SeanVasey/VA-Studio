<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccessPolicy;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\Preferences\ConsentRuntime;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerConsentAdmissionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const MIGRATION = '2026_10_07_250000_customer_consent';

    private const TABLES = ['customer_consent_policies', 'customer_consent_events', 'customer_consent_states'];

    private function migration(): object
    {
        return require getenv('CONSENT_ADMISSION_BASELINE_MIGRATION') ?: database_path('migrations/'.self::MIGRATION.'.php');
    }

    private function prepare(bool $complete = false): void
    {
        // Actual full-source migrations prove all dependency shapes/guards before each adversarial fixture.
        $this->migration()->up();
        $customer = CustomerFixtures::account();
        if ($complete) {
            ConsentFixtures::configure();
            app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
        }
        if (! $complete) {
            foreach (array_reverse(self::TABLES) as $table) {
                Schema::drop($table);
            }
            DB::table('migrations')->where('migration', self::MIGRATION)->delete();
        }
        DB::unprepared('CREATE TABLE consent_review_sentinel (id INTEGER PRIMARY KEY, private_value TEXT)');
        DB::table('consent_review_sentinel')->insert(['id' => 1, 'private_value' => 'SYNTHETIC retained foreign row']);
    }

    private function snapshot(): array
    {
        $pdo = DB::connection()->getPdo();
        $schema = DB::getDriverName() === 'sqlite' ? $pdo->query('SELECT type,name,tbl_name,sql FROM main.sqlite_master ORDER BY name,type')->fetchAll(PDO::FETCH_ASSOC)
            : [$pdo->query('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_ASSOC),
                $pdo->query('SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() ORDER BY TRIGGER_NAME')->fetchAll(PDO::FETCH_ASSOC)];
        $rows = [];
        foreach (['users', 'customer_accounts', 'quote_owners', ...self::TABLES, 'consent_review_sentinel', 'migrations'] as $table) {
            $exists = $pdo->prepare(DB::getDriverName() === 'sqlite' ? "SELECT COUNT(*) FROM main.sqlite_master WHERE type='table' AND name=?" : 'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
            $exists->execute([$table]);
            if ((int) $exists->fetchColumn() !== 0) {
                $rows[$table] = $pdo->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        return [$schema, $rows];
    }

    private function refusedWithoutDDL(): void
    {
        $before = $this->snapshot();
        $ddl = 0;
        DB::listen(function ($query) use (&$ddl): void {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl++;
            }
        });
        try {
            $this->migration()->up();
            $this->fail('Unowned namespace or dependency was accepted.');
        } catch (LogicException) {
            $this->assertSame(0, $ddl, 'Admission must fail before the first DDL.');
            $this->assertSame($before, $this->snapshot(), 'All retained schema and data must remain exact.');
        }
    }

    public static function collisions(): array
    {
        return ['table name as trigger' => ['table-trigger'], 'complete table and same-name foreign trigger' => ['coexisting'],
            'guard name as table' => ['guard-table'], 'guard name as index' => ['guard-index'], 'dictionary case/accent alias' => ['alias']];
    }

    #[DataProvider('collisions')]
    public function test_reserved_identity_across_object_kinds_is_refused_on_full_valid_dependencies(string $case): void
    {
        $this->prepare($case === 'coexisting');
        $driver = DB::getDriverName();
        $name = $case === 'alias' ? ($driver === 'sqlite' ? 'CUSTOMER_CONSENT_POLICIES' : 'customér_consent_policies') : 'customer_consent_policies';
        if (in_array($case, ['table-trigger', 'coexisting', 'alias'], true)) {
            DB::unprepared('CREATE TRIGGER `'.$name.'` BEFORE INSERT ON consent_review_sentinel '.($driver === 'mysql' ? 'FOR EACH ROW SET NEW.id=NEW.id' : 'BEGIN SELECT 1; END'));
        } elseif ($case === 'guard-table') {
            DB::unprepared('CREATE TABLE customer_consent_policies_retain_insert (private_value TEXT)');
            DB::table('customer_consent_policies_retain_insert')->insert(['private_value' => 'SYNTHETIC foreign contents']);
        } else {
            DB::unprepared('CREATE INDEX customer_consent_policies_retain_insert ON consent_review_sentinel (private_value)');
        }
        $this->refusedWithoutDDL();
    }

    public static function dependencies(): array
    {
        return array_combine(['missing users', 'missing accounts', 'missing owners', 'account column drift', 'missing account identity key', 'missing user email key', 'missing account retention guard',
            'missing user discovery guard', 'missing owner retention guard', 'foreign-key enforcement disabled', 'temporary users', 'temporary accounts', 'temporary owners'],
            array_map(fn ($n) => [$n], range(0, 12)));
    }

    #[DataProvider('dependencies')]
    public function test_dependency_shape_keys_guards_enforcement_and_shadow_refuse_before_schema_installation(int $case): void
    {
        $this->prepare();
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $temporary = null;
        if ($case < 3) {
            $table = ['users', 'customer_accounts', 'quote_owners'][$case];
            $pdo->exec($driver === 'sqlite' ? 'PRAGMA foreign_keys=OFF' : 'SET FOREIGN_KEY_CHECKS=0');
            $pdo->exec('DROP TABLE '.$table);
            $pdo->exec($driver === 'sqlite' ? 'PRAGMA foreign_keys=ON' : 'SET FOREIGN_KEY_CHECKS=1');
        } elseif ($case === 3) {
            $pdo->exec('ALTER TABLE customer_accounts ADD COLUMN foreign_authority TEXT');
        } elseif (in_array($case, [4, 5], true)) {
            $table = $case === 4 ? 'customer_accounts' : 'users';
            $index = $case === 4 ? 'customer_accounts_public_id_unique' : 'users_email_unique';
            $pdo->exec('DROP INDEX '.$index.($driver === 'mysql' ? ' ON '.$table : ''));
        } elseif (in_array($case, [6, 7, 8], true)) {
            $pdo->exec('DROP TRIGGER '.['customer_accounts_update', 'cde_0_update', 'quote_owners_immutable_update'][$case - 6]);
        } elseif ($case === 9) {
            $pdo->exec($driver === 'sqlite' ? 'PRAGMA foreign_keys=OFF' : 'SET FOREIGN_KEY_CHECKS=0');
        } else {
            $temporary = ['users', 'customer_accounts', 'quote_owners'][$case - 10];
            $pdo->exec('CREATE TEMPORARY TABLE '.$temporary.' (private_value TEXT)');
        }
        try {
            $this->refusedWithoutDDL();
        } finally {
            if ($case === 9) {
                $pdo->exec($driver === 'sqlite' ? 'PRAGMA foreign_keys=ON' : 'SET FOREIGN_KEY_CHECKS=1');
            }
            if ($temporary !== null) {
                $pdo->exec($driver === 'sqlite' ? 'DROP TABLE temp.'.$temporary : 'DROP TEMPORARY TABLE '.$temporary);
            }
        }
    }

    public static function finalDDLChanges(): array
    {
        return ['earlier own guard' => ['customer_consent_policies_retain_insert'], 'retained account dependency guard' => ['customer_accounts_update']];
    }

    #[DataProvider('finalDDLChanges')]
    public function test_final_framework_ddl_callback_cannot_record_a_missing_earlier_guard(string $guard): void
    {
        $this->prepare();
        $fired = false;
        DB::listen(function ($query) use (&$fired, $guard): void {
            if (! $fired && str_starts_with($query->sql, 'CREATE TRIGGER `customer_consent_states_retain_delete`')) {
                $fired = true;
                DB::unprepared('DROP TRIGGER '.$guard);
            }
        });
        try {
            $this->artisan('migrate', ['--path' => 'database/migrations/'.self::MIGRATION.'.php', '--force' => true])->run();
            $this->fail('Incomplete schema was recorded by the actual migrator.');
        } catch (LogicException) {
            $this->assertTrue($fired);
            $this->assertSame(0, DB::table('migrations')->where('migration', self::MIGRATION)->count());
            $this->assertSame('SYNTHETIC retained foreign row', DB::table('consent_review_sentinel')->value('private_value'));
        }
    }

    public function test_terminal_container_policy_resolution_cannot_commit_outdated_grant(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $before = $this->snapshot();
        $resolutions = 0;
        $fired = false;
        $this->app->resolving(CustomerAccessPolicy::class, function () use (&$resolutions, &$fired): void {
            if (++$resolutions === 3) {
                $fired = true;
                config(['customer-preferences.test_grants_enabled' => false, 'customer-preferences.email_marketing' => null]);
            }
        });
        try {
            app(CustomerConsentPreferences::class)->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
            $this->fail('Terminal policy callback escaped.');
        } catch (ConsentException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertTrue($fired);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_terminal_runtime_callback_cannot_change_notice_after_configuration_check(): void
    {
        ConsentFixtures::configure();
        $customer = CustomerFixtures::account();
        $before = $this->snapshot();
        $runtime = new class implements ConsentRuntime
        {
            public int $calls = 0;

            public function grantsEnabled(): bool
            {
                if (++$this->calls === 2) {
                    config(['customer-preferences.email_marketing' => null]);
                }

                return true;
            }
        };
        try {
            (new CustomerConsentPreferences($runtime))->change($customer['principal'], $customer['user'], ConsentFixtures::grant());
            $this->fail('Terminal runtime callback escaped.');
        } catch (ConsentException $error) {
            $this->assertSame(503, $error->status);
        }
        $this->assertSame(2, $runtime->calls);
        $this->assertSame($before, $this->snapshot());
    }
}
