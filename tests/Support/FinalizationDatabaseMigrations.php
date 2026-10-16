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
        $this->requireFixtureDatabase();
        $this->beforeRefreshingDatabase();
        $options = $this->migrateFreshUsing();
        $connection = $this->requireFixtureDatabase($options['--database'] ?? null);
        $name = $connection->getName();
        $options['--database'] = $name;
        $result = $this->artisan('migrate:fresh', $options);
        $status = $result instanceof PendingCommand ? $result->run() : $result;
        $this->app[Kernel::class]->setArtisan(null);
        if ($status !== 0) {
            throw new LogicException('Disposable finalization fixture setup failed.');
        }
        $this->afterRefreshingDatabase();

        $cleanup = array_intersect_key($options, array_flip(['--database', '--drop-views', '--drop-types']));
        $identity = array_intersect_key($connection->getConfig(), array_flip(['driver', 'database', 'host', 'port', 'unix_socket', 'url']));
        $this->beforeApplicationDestroyed(function () use ($name, $cleanup, $identity) {
            // Drop disposable fixtures without rebuilding a schema that the next setup rebuilds.
            // Never call the guarded operational down(), and retain the setup's connection/options.
            try {
                $current = $this->requireFixtureDatabase($name);
                if (array_intersect_key($current->getConfig(), array_flip(array_keys($identity))) !== $identity) {
                    throw new LogicException('Disposable test database admission refused.');
                }
                $result = $this->artisan('db:wipe', $cleanup);
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
