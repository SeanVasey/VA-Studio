<?php

namespace Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use LogicException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DisposableTestDatabase;
use Tests\TestCase;

class DisposableTestDatabaseTest extends TestCase
{
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['APP_ENV', 'DB_CONNECTION', 'DB_DATABASE', 'VA_LOCAL_DISPOSABLE_MYSQL', 'VA_CI_DISPOSABLE_MYSQL', 'CI'] as $name) {
            $this->saved[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
        parent::tearDown();
    }

    private function selection(array $changes = []): void
    {
        foreach (array_replace(['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
            'VA_LOCAL_DISPOSABLE_MYSQL' => null, 'VA_CI_DISPOSABLE_MYSQL' => null, 'CI' => null], $changes) as $name => $value) {
            putenv($value === null ? $name : $name.'='.$value);
        }
    }

    private function refuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The fixture selection was admitted.');
        } catch (LogicException $error) {
            $this->assertSame('Disposable test database admission refused.', $error->getMessage());
        }
    }

    public static function invalidSelections(): array
    {
        return ['missing environment' => [['APP_ENV' => null]], 'production environment' => [['APP_ENV' => 'production']],
            'missing driver' => [['DB_CONNECTION' => null]], 'unsupported driver' => [['DB_CONNECTION' => 'pgsql']],
            'missing database' => [['DB_DATABASE' => null]], 'empty database' => [['DB_DATABASE' => '']],
            'SQLite file' => [['DB_DATABASE' => '/tmp/explicitly-unopened.sqlite']],
            'MySQL delimiter' => [['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'fixture;other']],
            'oversized MySQL identifier' => [['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => str_repeat('x', 65)]]];
    }

    #[DataProvider('invalidSelections')]
    public function test_invalid_external_selections_refuse_without_opening_a_connection(array $changes): void
    {
        $this->selection($changes);
        $this->refuses(fn () => DisposableTestDatabase::capture());
    }

    public function test_selected_memory_identity_remains_bound_after_environment_changes(): void
    {
        $this->selection();
        $selection = DisposableTestDatabase::capture();
        $this->selection(['APP_ENV' => 'production', 'DB_DATABASE' => '/tmp/explicitly-unopened.sqlite']);
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite']);
        $this->assertSame($connection, $selection->admit($connection));
    }

    public function test_actual_file_database_cannot_be_presented_as_an_in_memory_connection(): void
    {
        $this->selection();
        $file = tempnam(sys_get_temp_dir(), 'fixture-admission-');
        try {
            $connection = new SQLiteConnection(new PDO('sqlite:'.$file), ':memory:', '', ['driver' => 'sqlite']);
            $this->refuses(fn () => DisposableTestDatabase::capture()->admit($connection));
        } finally {
            unset($connection);
            unlink($file);
        }
    }

    public function test_actual_pdo_driver_cannot_be_replaced_by_a_mysql_configuration_label(): void
    {
        $this->selection(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'vaseyaudio_fixture_admission',
            'VA_LOCAL_DISPOSABLE_MYSQL' => 'vaseyaudio_fixture_admission']);
        $connection = new MySqlConnection(new PDO('sqlite::memory:'), 'vaseyaudio_fixture_admission', '', ['driver' => 'mysql']);
        $this->refuses(fn () => DisposableTestDatabase::capture()->admit($connection));
    }

    public static function incompleteMySqlDeclarations(): array
    {
        return ['missing markers' => [[]], 'wrong local schema' => [['VA_LOCAL_DISPOSABLE_MYSQL' => 'vaseyaudio_other']],
            'CI missing runner flag' => [['VA_CI_DISPOSABLE_MYSQL' => '1']],
            'CI missing disposable flag' => [['CI' => 'true']],
            'CI wrong flag' => [['CI' => 'true', 'VA_CI_DISPOSABLE_MYSQL' => 'true']],
            'CI invalid schema' => [['CI' => 'true', 'VA_CI_DISPOSABLE_MYSQL' => '1', 'DB_DATABASE' => 'production']]];
    }

    #[DataProvider('incompleteMySqlDeclarations')]
    public function test_mysql_without_an_exact_disposable_declaration_refuses_before_pdo_connection(array $changes): void
    {
        $this->selection(array_replace(['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'vaseyaudio_fixture_admission'], $changes));
        $opened = false;
        $connection = new MySqlConnection(function () use (&$opened): PDO {
            $opened = true;
            throw new LogicException('The connection must not be opened.');
        }, getenv('DB_DATABASE'), '', ['driver' => 'mysql']);
        $this->refuses(fn () => DisposableTestDatabase::capture()->admit($connection));
        $this->assertFalse($opened);
    }
}
