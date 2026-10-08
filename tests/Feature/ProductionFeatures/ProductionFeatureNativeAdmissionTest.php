<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Listening\ListeningLibrary;
use App\Domain\Customers\Preferences\CustomerConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureSchema;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionSchema;
use App\Support\CanonicalJson;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConsentFixtures;
use Tests\Support\CustomerFixtures;
use Tests\TestCase;

class ProductionFeatureNativeAdmissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual native dictionary comparisons and temporary namespace admission.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $customer = CustomerFixtures::account(['email' => 'legacy-253-native-review@example.test']);
        (new ListeningLibrary)->change($customer['principal'], $customer['user'], ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC retained legacy playlist']);
        (new CustomerConsentPreferences)->change($customer['principal'], $customer['user'], ConsentFixtures::withdraw());
        $history = $this->history();
        $this->resetProduction();
        (new ProductionFeatureSchema)->up(); // Same complete dependencies must pass before collision injection.
        $this->assertSame($history, $this->history());
        $this->resetProduction();
    }

    public static function constraints(): array
    {
        return ['exact' => [false], 'native uppercase alias' => [true]];
    }

    #[DataProvider('constraints')]
    public function test_schemawide_foreign_constraint_and_dictionary_alias_refuse_before_ddl(bool $alias): void
    {
        $expected = 'production_consent_events_binding_id_fk';
        $name = $alias ? strtoupper($expected) : $expected;
        DB::unprepared('CREATE TABLE production_feature_foreign_review (id BIGINT UNSIGNED PRIMARY KEY,user_id BIGINT UNSIGNED,CONSTRAINT `'.$name.'` FOREIGN KEY (user_id) REFERENCES users(id)) ENGINE=InnoDB');
        DB::table('production_feature_foreign_review')->insert(['id' => 1, 'user_id' => DB::table('users')->value('id')]);
        $statement = DB::connection()->getPdo()->prepare('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=?');
        $statement->execute(['production_feature_foreign_review', $expected]);
        $this->assertSame([$name], $statement->fetchAll(\PDO::FETCH_COLUMN), 'Prove the actual dictionary compares this reserved name/alias.');
        $this->refuses('key');
        $this->assertSame(1, DB::table('production_feature_foreign_review')->count());
    }

    public function test_temporary_table_using_an_owned_guard_name_refuses_before_ddl(): void
    {
        DB::unprepared('CREATE TEMPORARY TABLE production_consent_states_retain_insert (marker INTEGER PRIMARY KEY)');
        DB::unprepared('INSERT INTO production_consent_states_retain_insert VALUES (1)');
        try {
            $this->refuses('Temporary');
            $this->assertSame(1, DB::table('production_consent_states_retain_insert')->count());
        } finally {
            DB::unprepared('DROP TEMPORARY TABLE production_consent_states_retain_insert');
        }
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
            (new ProductionFeatureSchema)->up();
            $this->fail('Native reserved namespace must refuse before any owned DDL.');
        } catch (LogicException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
            $this->assertSame([], $ddl);
            $this->assertSame($before, $this->history());
            foreach (ProductionFeatureSchema::TABLES as $table) {
                $this->assertFalse(DB::getSchemaBuilder()->hasTable($table));
            }
        }
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

    private function history(): string
    {
        $rows = [];
        foreach (['users', 'customer_accounts', 'customer_saved_tracks', 'customer_consent_policies', 'customer_consent_events', 'customer_consent_states'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return hash('sha256', CanonicalJson::encode($rows));
    }
}
