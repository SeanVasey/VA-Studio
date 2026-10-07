<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\Preferences\Suppression\SuppressionSchema;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionSchema;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

/** Native MySQL dictionary/namespace guards for the 254 installer. SQLite cannot prove these. */
class ProductionSuppressionNativeAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual native dictionary comparisons and temporary namespace admission.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $customer = CustomerFixtures::account(['email' => 'legacy-254-native-review@example.test']);
        ConsentFixtures::configure();
        (new CustomerConsentPreferences)->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw());
        $this->reset();
        $history = $this->history();
        (new ProductionSuppressionSchema)->up(); // Complete dependencies must pass before collision injection.
        $this->assertSame($history, $this->history());
        $this->reset();
    }

    public static function constraints(): array
    {
        return ['exact' => [false], 'native uppercase alias' => [true]];
    }

    #[DataProvider('constraints')]
    public function test_schemawide_foreign_constraint_and_dictionary_alias_refuse_before_ddl(bool $alias): void
    {
        $expected = 'production_suppression_targets_withdrawal_event_id_fk';
        $name = $alias ? strtoupper($expected) : $expected;
        DB::unprepared('CREATE TABLE production_suppression_foreign_review (id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,CONSTRAINT `'.$name.'` FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB');
        DB::table('production_suppression_foreign_review')->insert(['id' => 1, 'user_id' => DB::table('users')->value('id')]);
        $statement = DB::connection()->getPdo()->prepare('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $statement->execute(['production_suppression_foreign_review', $expected]);
        $this->assertSame([$name], $statement->fetchAll(PDO::FETCH_COLUMN), 'Prove the actual dictionary compares this reserved name/alias.');
        $this->refuses('key');
        $this->assertSame(1, DB::table('production_suppression_foreign_review')->count());
    }

    public function test_owned_table_name_used_by_a_foreign_trigger_refuses_before_ddl(): void
    {
        DB::unprepared('CREATE TABLE production_suppression_foreign_review (id BIGINT UNSIGNED PRIMARY KEY) ENGINE=InnoDB');
        DB::unprepared('CREATE TRIGGER `production_suppression_attempts` BEFORE INSERT ON production_suppression_foreign_review FOR EACH ROW SET NEW.id=NEW.id');
        $this->refuses('namespace');
    }

    public function test_temporary_table_using_an_owned_guard_name_refuses_before_ddl(): void
    {
        DB::unprepared('CREATE TEMPORARY TABLE production_suppression_intents_retain_insert (marker INTEGER PRIMARY KEY)');
        DB::unprepared('INSERT INTO production_suppression_intents_retain_insert VALUES (1)');
        try {
            $this->refuses('Temporary');
            $this->assertSame(1, DB::table('production_suppression_intents_retain_insert')->count());
        } finally {
            DB::unprepared('DROP TEMPORARY TABLE production_suppression_intents_retain_insert');
        }
    }

    public function test_temporary_shadow_of_an_owned_table_refuses_held_admission(): void
    {
        (new ProductionSuppressionSchema)->up();
        DB::unprepared('CREATE TEMPORARY TABLE production_suppression_targets (id BIGINT UNSIGNED PRIMARY KEY)');
        try {
            (new ProductionSuppressionSchema)->assertHeld(DB::connection()->getPdo(), 'mysql', DB::connection()->getDatabaseName());
            $this->fail('A session TEMPORARY shadow must refuse held admission.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('Temporary', $error->getMessage());
        } finally {
            DB::unprepared('DROP TEMPORARY TABLE production_suppression_targets');
        }
    }

    public function test_native_installation_has_exact_lineage_and_retention_guards(): void
    {
        (new ProductionSuppressionSchema)->up();
        $statement = DB::connection()->getPdo()->prepare('SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME LIKE ? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,COLUMN_NAME');
        $statement->execute(['production\_suppression\_%']);
        $this->assertSame([
            ['TABLE_NAME' => 'production_suppression_attempts', 'COLUMN_NAME' => 'intent_id', 'REFERENCED_TABLE_NAME' => 'production_suppression_intents'],
            ['TABLE_NAME' => 'production_suppression_attempts', 'COLUMN_NAME' => 'target_id', 'REFERENCED_TABLE_NAME' => 'production_suppression_targets'],
            ['TABLE_NAME' => 'production_suppression_confirmations', 'COLUMN_NAME' => 'attempt_id', 'REFERENCED_TABLE_NAME' => 'production_suppression_attempts'],
            ['TABLE_NAME' => 'production_suppression_intents', 'COLUMN_NAME' => 'target_id', 'REFERENCED_TABLE_NAME' => 'production_suppression_targets'],
            ['TABLE_NAME' => 'production_suppression_intents', 'COLUMN_NAME' => 'withdrawal_event_id', 'REFERENCED_TABLE_NAME' => 'production_consent_events'],
            ['TABLE_NAME' => 'production_suppression_targets', 'COLUMN_NAME' => 'binding_id', 'REFERENCED_TABLE_NAME' => 'production_account_feature_bindings'],
            ['TABLE_NAME' => 'production_suppression_targets', 'COLUMN_NAME' => 'withdrawal_event_id', 'REFERENCED_TABLE_NAME' => 'production_consent_events'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
        $statement = DB::connection()->getPdo()->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE LIKE ?');
        $statement->execute(['production\_suppression\_%']);
        $this->assertSame(12, (int) $statement->fetchColumn());
        (new ProductionSuppressionSchema)->assertComplete(DB::connection()->getPdo(), 'mysql', DB::connection()->getDatabaseName());
    }

    private function refuses(string $message): void
    {
        $before = $this->history();
        $ddl = [];
        DB::listen(function (QueryExecuted $query) use (&$ddl) {
            if (preg_match('/\A(?:CREATE|ALTER|DROP)\b/i', $query->sql)) {
                $ddl[] = $query->sql;
            }
        });
        try {
            (new ProductionSuppressionSchema)->up();
            $this->fail('Native reserved namespace must refuse before any owned DDL.');
        } catch (LogicException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame([], $ddl);
            $this->assertSame($before, $this->history());
            foreach (ProductionSuppressionSchema::TABLES as $table) {
                $this->assertFalse(DB::getSchemaBuilder()->hasTable($table));
            }
        }
    }

    private function reset(): void
    {
        foreach (array_reverse(ProductionSuppressionSchema::TABLES) as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $this->assertSame(0, DB::table($table)->count());
                DB::unprepared('DROP TABLE `'.$table.'`');
            }
        }
        DB::table('migrations')->where('migration', ProductionSuppressionSchema::MIGRATION)->delete();
    }

    private function history(): string
    {
        $rows = [];
        foreach (['users', 'customer_accounts', 'customer_consent_events', 'customer_consent_states', ...SuppressionSchema::TABLES, 'production_account_feature_bindings', 'production_consent_events'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', CanonicalJson::encode($rows));
    }
}
