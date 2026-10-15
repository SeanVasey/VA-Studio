<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PDO;
use Tests\TestCase;

/** Child-only recipe probe; commands are recorded without executing destructive SQL. */
class FinalizationDatabaseAdmissionProbe extends TestCase
{
    use FinalizationDatabaseMigrations;

    private bool $changed = false;

    protected function beforeRefreshingDatabase()
    {
        if (getenv('VASEY_ADMISSION_DAMAGE') === 'setup-hook') {
            $this->app['config']->set('database.connections.sqlite.database', getenv('VASEY_ADMISSION_FILE'));
        }
    }

    protected function migrateFreshUsing(): array
    {
        return ['--database' => $this->changed ? 'alternate_fixture' : 'sqlite', '--drop-views' => $this->changed];
    }

    public function artisan($command, $parameters = [])
    {
        $this->record(['command' => $command, 'parameters' => $parameters,
            'default' => $this->app['db']->getDefaultConnection()]);

        return 0;
    }

    public function test_body_changes_only_its_owned_synthetic_fixture(): void
    {
        $this->record(['body' => true]);
        $database = $this->app['db'];
        $config = $this->app['config'];
        $config->set('database.connections.alternate_fixture', $config->get('database.connections.sqlite'));
        match (getenv('VASEY_ADMISSION_DAMAGE')) {
            'recipe' => $this->changed = true,
            'default' => $database->setDefaultConnection('alternate_fixture'),
            'configuration' => $this->redirectConfiguration(),
            'pdo' => $database->connection()->setPdo(new PDO('sqlite:'.getenv('VASEY_ADMISSION_FILE'))),
            'none' => null,
        };
        RefreshDatabaseState::$migrated = true;
        $this->beforeApplicationDestroyed(function (): void {
            $this->record(['bookkeeping_reset' => ! RefreshDatabaseState::$migrated]);
        });
        $this->assertTrue(true);
    }

    private function redirectConfiguration(): void
    {
        $this->app['config']->set('database.connections.sqlite.database', getenv('VASEY_ADMISSION_FILE'));
        $this->app['db']->purge('sqlite');
    }

    private function record(array $record): void
    {
        file_put_contents(getenv('VASEY_ADMISSION_SPY'), json_encode($record, JSON_THROW_ON_ERROR)."\n", FILE_APPEND | LOCK_EX);
    }
}
