<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Child-only framework boundary probe: the destructive helpers are observable spies. */
class FixtureDatabaseAdmissionProbe extends TestCase
{
    use DatabaseMigrations;
    use DatabaseTransactions;
    use DatabaseTruncation;
    use RefreshDatabase {
        RefreshDatabase::refreshTestDatabase insteadof DatabaseMigrations;
        RefreshDatabase::beforeRefreshingDatabase insteadof DatabaseMigrations;
        RefreshDatabase::afterRefreshingDatabase insteadof DatabaseMigrations;
        RefreshDatabase::connectionsToTransact insteadof DatabaseTransactions;
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $trait = match (getenv('VASEY_ADMISSION_TRAIT')) {
            'refresh' => RefreshDatabase::class,
            'migrations' => DatabaseMigrations::class,
            'truncation' => DatabaseTruncation::class,
            'transactions' => DatabaseTransactions::class,
        };
        $this->traitsUsedByTest = [$trait => $trait];
        if (getenv('VASEY_ADMISSION_DAMAGE') === 'configuration') {
            $app['config']->set('database.connections.sqlite.database', getenv('VASEY_ADMISSION_FILE'));
        }

        return $app;
    }

    public function refreshDatabase()
    {
        $this->record('refresh');
    }

    public function runDatabaseMigrations()
    {
        $this->record('migrations');
    }

    protected function truncateDatabaseTables(): void
    {
        $this->record('truncation');
    }

    public function beginDatabaseTransaction()
    {
        $this->record('transactions');
    }

    public function test_body_is_reached_only_after_framework_admission(): void
    {
        $this->record('body');
        $this->assertTrue(true);
    }

    private function record(string $boundary): void
    {
        file_put_contents(getenv('VASEY_ADMISSION_SPY'), $boundary."\n", FILE_APPEND | LOCK_EX);
    }
}
