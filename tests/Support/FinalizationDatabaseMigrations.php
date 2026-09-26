<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use LogicException;

/** Explicit disposable-test lifecycle; real finalization rollback refuses retained rights. */
trait FinalizationDatabaseMigrations
{
    use DatabaseMigrations;

    public function runDatabaseMigrations()
    {
        if (! $this->app->environment('testing')) {
            throw new LogicException('Finalization fixtures require the isolated testing environment.');
        }
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();

        $this->beforeApplicationDestroyed(function () {
            // migrate:fresh drops the disposable test database, never calls the guarded operational down().
            $this->refreshTestDatabase();
            RefreshDatabaseState::$migrated = false;
        });
    }
}
