<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Testing\PendingCommand;
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
            // Drop disposable fixtures without rebuilding a schema that the next setup rebuilds.
            // Never call the guarded operational down(), and retain the setup's connection/options.
            try {
                $result = $this->artisan('db:wipe', array_intersect_key($this->migrateFreshUsing(), array_flip([
                    '--database', '--drop-views', '--drop-types',
                ])));
                $status = $result instanceof PendingCommand ? $result->run() : $result;
                if ($status !== 0) {
                    throw new LogicException('Disposable finalization fixture cleanup failed.');
                }
            } finally {
                $this->app[Kernel::class]->setArtisan(null);
                RefreshDatabaseState::$migrated = false;
            }
        });
    }
}
