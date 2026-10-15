<?php

namespace Tests;

use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use LogicException;
use Tests\Support\DisposableTestDatabase;

abstract class TestCase extends BaseTestCase
{
    private DisposableTestDatabase $testDatabaseSelection;

    private array $ownedInMemorySqliteFixtures = [];

    protected function setUp(): void
    {
        // Capture before bootstrap; route-boot fixtures intentionally change application mode.
        $this->testDatabaseSelection = DisposableTestDatabase::capture();
        parent::setUp();

        // A component rendered through a real HTTP request (a Filament page, say) leaves Livewire's
        // static auto-injection state set (SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest).
        // That state outlives the application, so a later test in the same process would get Livewire's
        // <style>/<script> injected into every full-HTML response, such as the script-free public embed.
        Livewire::flushState();
    }

    protected function setUpTraits()
    {
        $this->requireFixtureDatabase();
        $uses = $this->traitsUsedByTest ?? class_uses_recursive(static::class);
        if (isset($uses[RefreshDatabase::class]) || isset($uses[DatabaseTransactions::class])) {
            foreach ($this->connectionsToTransact() as $name) {
                $this->requireFixtureDatabase($name);
            }
        }
        if (isset($uses[DatabaseTruncation::class])) {
            foreach ($this->connectionsToTruncate() as $name) {
                $this->requireFixtureDatabase($name);
            }
        }

        return parent::setUpTraits();
    }

    protected function requireFixtureDatabase(?string $name = null): Connection
    {
        $connection = $this->app['db']->connection($name);
        $configured = $this->app['config']->get('database.connections.'.$connection->getName());
        if (! is_array($configured) || ! in_array($configured['url'] ?? null, [null, ''], true)) {
            throw new LogicException('Disposable test database admission refused.');
        }
        foreach (['driver', 'database', 'host', 'port', 'unix_socket'] as $key) {
            if (($configured[$key] ?? null) !== $connection->getConfig($key)) {
                throw new LogicException('Disposable test database admission refused.');
            }
        }

        if (isset($this->ownedInMemorySqliteFixtures[$connection->getName()])) {
            $owned = $this->ownedInMemorySqliteFixtures[$connection->getName()];
            if ($connection !== $owned['connection'] || $connection->getPdo() !== $owned['pdo']) {
                throw new LogicException('Disposable test database admission refused.');
            }

            return $this->testDatabaseSelection->forInMemoryFixture()->admit($connection);
        }

        return $this->testDatabaseSelection->admit($connection);
    }

    /** Create an owned memory canary without changing the external database selection. */
    protected function createInMemorySqliteFixture(): Connection
    {
        $this->requireFixtureDatabase();
        $name = 'owned_sqlite_fixture_'.bin2hex(random_bytes(16));
        if ($this->app['config']->has('database.connections.'.$name)) {
            throw new LogicException('Disposable test database admission refused.');
        }
        $this->app['config']->set('database.connections.'.$name, [
            'driver' => 'sqlite', 'database' => ':memory:', 'url' => null,
            'prefix' => '', 'foreign_key_constraints' => true,
        ]);
        $connection = $this->app['db']->connection($name);
        $this->testDatabaseSelection->forInMemoryFixture()->admit($connection);
        $this->ownedInMemorySqliteFixtures[$name] = ['connection' => $connection, 'pdo' => $connection->getPdo()];
        $this->beforeApplicationDestroyed(function () use ($name): void {
            $this->app['db']->purge($name);
            unset($this->ownedInMemorySqliteFixtures[$name]);
        });

        return $connection;
    }

    public function artisan($command, $parameters = [])
    {
        if (in_array($command, ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback', 'db:wipe', 'db:seed'], true)) {
            $this->requireFixtureDatabase($parameters['--database'] ?? null);
        }

        return parent::artisan($command, $parameters);
    }

    protected function fakePrivateMediaStorage(): void
    {
        // Each test owns its storage, including across separate PHPUnit processes.
        // Storage::fake() ignores a custom root; a unique disk name supplies it.
        $name = 'local-'.Str::uuid();
        $disk = Storage::fake($name, config('filesystems.disks.local'));
        Storage::set('local', $disk);
        $root = $disk->path('');
        // Keep read-only local adapters on the same already-created isolated root without resolving Storage.
        config(['filesystems.disks.local.root' => rtrim($root, '/')]);
        $this->beforeApplicationDestroyed(function () use ($root) {
            (new Filesystem)->deleteDirectory($root);
        });
    }
}
