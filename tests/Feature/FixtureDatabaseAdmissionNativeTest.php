<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\DisposableTestDatabase;
use Tests\TestCase;

#[Group('native-mysql')]
class FixtureDatabaseAdmissionNativeTest extends TestCase
{
    public static function productModes(): array
    {
        return ['testing' => ['testing'], 'production boot' => ['production'],
            'staging boot' => ['staging'], 'local boot' => ['local']];
    }

    private function native(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Actual MySQL PDO identity requires the owned native test server.');
        }
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Another physical database or driver was admitted.');
        } catch (LogicException $error) {
            $this->assertSame('Disposable test database admission refused.', $error->getMessage());
        }
    }

    #[DataProvider('productModes')]
    public function test_captured_testing_selection_survives_deliberate_product_route_boot_modes(string $mode): void
    {
        $this->native();
        $original = $this->app['env'];
        try {
            $this->app['env'] = $mode;
            $this->assertSame(DB::connection(), $this->requireFixtureDatabase());
            $this->assertSame(getenv('DB_DATABASE'), DB::getPdo()->query('SELECT DATABASE()')->fetchColumn());
        } finally {
            $this->app['env'] = $original;
        }
    }

    public function test_ci_declaration_uses_external_testing_even_in_a_production_boot_fixture(): void
    {
        $this->native();
        $saved = [];
        foreach (['VA_LOCAL_DISPOSABLE_MYSQL', 'VA_CI_DISPOSABLE_MYSQL', 'CI'] as $name) {
            $saved[$name] = getenv($name);
        }
        $original = $this->app['env'];
        try {
            putenv('VA_LOCAL_DISPOSABLE_MYSQL');
            putenv('VA_CI_DISPOSABLE_MYSQL=1');
            putenv('CI=true');
            $this->app['env'] = 'production';
            $this->assertSame(DB::connection(), DisposableTestDatabase::capture()->admit(DB::connection()));
        } finally {
            $this->app['env'] = $original;
            foreach ($saved as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
        }
    }

    public function test_an_alias_of_the_selected_native_database_is_admitted(): void
    {
        $this->native();
        config(['database.connections.admission_alias' => config('database.connections.'.DB::getDefaultConnection())]);
        $alias = $this->requireFixtureDatabase('admission_alias');
        $this->assertSame('admission_alias', $alias->getName());
        $this->assertSame(getenv('DB_DATABASE'), $alias->getPdo()->query('SELECT DATABASE()')->fetchColumn());
        DB::purge('admission_alias');
    }

    public function test_actual_use_of_another_owned_schema_refuses_despite_unchanged_configuration(): void
    {
        $this->native();
        $pdo = DB::getPdo();
        $selected = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $peer = 'vaseyaudio_fixture_admission_peer';
        $this->assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '$peer'")->fetchColumn());
        $pdo->exec("CREATE DATABASE `$peer`");
        try {
            $pdo->exec("USE `$peer`");
            $pdo->exec('CREATE TABLE retained_fixture (sentinel INT NOT NULL) ENGINE=InnoDB');
            $pdo->exec('INSERT INTO retained_fixture VALUES (7123)');
            $this->refuses(fn () => $this->requireFixtureDatabase());
            $this->assertSame([7123], $pdo->query('SELECT sentinel FROM retained_fixture')->fetchAll(PDO::FETCH_COLUMN));
        } finally {
            $pdo->exec("USE `$selected`");
            $pdo->exec("DROP DATABASE `$peer`");
        }
    }

    public function test_an_actual_sqlite_pdo_cannot_supply_native_mysql_identity(): void
    {
        $this->native();
        $connection = new MySqlConnection(new PDO('sqlite::memory:'), getenv('DB_DATABASE'), '', ['driver' => 'mysql']);
        $this->refuses(fn () => DisposableTestDatabase::capture()->admit($connection));
    }
}
