<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Tests\TestCase;

/** Child PHPUnit fixture; only the parent lifecycle test supplies its private receipt path. */
class FinalizationLifecycleFixture extends TestCase
{
    protected bool $dropViews;

    use FinalizationDatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || getenv('VASEY_LIFECYCLE_RECEIPT') === false) {
            throw new LogicException('The lifecycle fixture requires its isolated testing parent.');
        }
        if (getenv('VASEY_LIFECYCLE_ALIAS') === '1') {
            $original = $app['config']->get('database.default');
            $app['config']->set('database.connections.lifecycle_fixture', $app['config']->get('database.connections.'.$original));
            $app['db']->setDefaultConnection('lifecycle_fixture');
        }

        return $app;
    }

    protected function setUp(): void
    {
        $this->dropViews = getenv('VASEY_LIFECYCLE_DROP_VIEWS') === '1';
        parent::setUp();
        $connection = DB::getDefaultConnection();
        $this->assertSame(getenv('VASEY_LIFECYCLE_ALIAS') === '1' ? 'lifecycle_fixture' : getenv('DB_CONNECTION'), $connection);
        $this->assertDatabaseCount('users', 0);
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
        $this->beforeApplicationDestroyed(function () use ($connection): void {
            // This callback runs after the trait's cleanup, through the real PHPUnit lifecycle.
            $this->assertSame($connection, DB::getDefaultConnection());
            $this->assertFalse(RefreshDatabaseState::$migrated);
            $tables = DB::connection()->getSchemaBuilder()->getTables();
            $views = DB::connection()->getSchemaBuilder()->getViews();
            $this->assertSame([], $tables);
            // SQLite's complete database reset removes views too; MySQL retains unrequested views.
            $retainsViews = ! $this->dropViews && DB::getDriverName() === 'mysql';
            $this->assertCount($retainsViews ? 1 : 0, $views);
            if ($retainsViews) {
                DB::statement('DROP VIEW lifecycle_retained_view');
            }
            file_put_contents(getenv('VASEY_LIFECYCLE_RECEIPT'), json_encode([
                'test' => $this->name(), 'connection' => $connection, 'tables' => count($tables),
                'views' => count($views), 'migrated' => RefreshDatabaseState::$migrated,
                'transaction_level' => DB::transactionLevel(), 'callback_after_cleanup' => true,
            ], JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
        });
    }

    public function test_first_fixture_is_removed_even_when_its_body_throws(): void
    {
        $this->writeDisposableFixture();
        if (getenv('VASEY_LIFECYCLE_THROW') === '1') {
            throw new RuntimeException('EXPECTED_LIFECYCLE_BODY_FAILURE');
        }
    }

    public function test_next_setup_recreates_the_complete_schema_without_previous_rows(): void
    {
        // setUp verifies the complete migration census and absence of the preceding row.
        $this->writeDisposableFixture();
    }

    private function writeDisposableFixture(): void
    {
        DB::table('users')->insert(['name' => 'Synthetic lifecycle fixture', 'email' => 'lifecycle@example.test',
            'password' => 'synthetic-unused-password']);
        DB::statement('CREATE VIEW lifecycle_retained_view AS SELECT id FROM users');
        $this->assertDatabaseCount('users', 1);
        $this->assertCount(1, DB::connection()->getSchemaBuilder()->getViews());
        RefreshDatabaseState::$migrated = true;
    }
}
