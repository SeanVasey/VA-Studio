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
use PDO;
use Tests\Support\DisposableTestDatabase;

abstract class TestCase extends BaseTestCase
{
    private DisposableTestDatabase $testDatabaseSelection;

    private array $ownedSqliteFixtures = [];

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

        if (isset($this->ownedSqliteFixtures[$connection->getName()])) {
            $owned = $this->ownedSqliteFixtures[$connection->getName()];
            if ($connection !== $owned['connection'] || $connection->getPdo() !== $owned['pdo']
                || $configured !== $owned['configuration']) {
                throw new LogicException('Disposable test database admission refused.');
            }

            if ($owned['file'] !== null) {
                $this->requireOwnedSqliteFile($owned['file']);
                $pdo = $connection->getPdo();
                if ($connection->getDriverName() !== 'sqlite' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
                    || $connection->getDatabaseName() !== $owned['file']['path']) {
                    throw new LogicException('Disposable test database admission refused.');
                }
                $main = array_values(array_filter($pdo->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC),
                    static fn (array $row): bool => $row['name'] === 'main'));
                if (count($main) !== 1 || $main[0]['file'] !== $owned['file']['path']) {
                    throw new LogicException('Disposable test database admission refused.');
                }

                return $connection;
            }

            return $this->testDatabaseSelection->forInMemoryFixture()->admit($connection);
        }

        return $this->testDatabaseSelection->admit($connection);
    }

    /** Create an owned memory canary without changing the external database selection. */
    protected function createInMemorySqliteFixture(): Connection
    {
        $this->requireFixtureDatabase();

        return $this->createOwnedSqliteFixture(':memory:');
    }

    /** Allocate a new private file; callers cannot register an existing database path. */
    protected function createFileSqliteFixture(): Connection
    {
        $this->requireFixtureDatabase();
        $base = storage_path('framework/testing');
        (new Filesystem)->ensureDirectoryExists($base, 0700);
        $directory = $base.'/owned-sqlite-fixture-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700) || is_link($directory) || realpath($directory) !== $directory) {
            throw new LogicException('Disposable test database admission refused.');
        }
        $path = $directory.'/database.sqlite';
        $mask = umask(0077);
        try {
            $stream = fopen($path, 'x+b');
            if ($stream === false) {
                throw new LogicException('Disposable test database admission refused.');
            }
            fclose($stream);
        } finally {
            umask($mask);
        }
        $file = ['path' => $path, 'directory' => $directory,
            'file_identity' => $this->sqliteFileIdentity($path),
            'directory_identity' => $this->sqliteFileIdentity($directory)];
        $this->requireOwnedSqliteFile($file);

        return $this->createOwnedSqliteFixture($path, $file);
    }

    private function createOwnedSqliteFixture(string $database, ?array $file = null): Connection
    {
        $name = 'owned_sqlite_fixture_'.bin2hex(random_bytes(16));
        if ($this->app['config']->has('database.connections.'.$name)
            || array_key_exists($name, $this->app['db']->getConnections())) {
            throw new LogicException('Disposable test database admission refused.');
        }
        $configuration = [
            'driver' => 'sqlite', 'database' => $database, 'url' => null,
            'prefix' => '', 'foreign_key_constraints' => true,
        ];
        if ($file !== null) {
            $configuration += ['journal_mode' => 'DELETE', 'transaction_mode' => 'DEFERRED'];
        }
        $this->app['config']->set('database.connections.'.$name, $configuration);
        $connection = $this->app['db']->connection($name);
        $this->ownedSqliteFixtures[$name] = ['connection' => $connection, 'pdo' => $connection->getPdo(),
            'configuration' => $configuration, 'file' => $file];
        $this->requireFixtureDatabase($name);
        $this->beforeApplicationDestroyed(function () use ($name): void {
            $this->releaseOwnedSqliteFixture($name);
        });

        return $connection;
    }

    /** Release strong source references before an independent restore is reopened. */
    protected function releaseOwnedSqliteFixture(string $name): void
    {
        if (! isset($this->ownedSqliteFixtures[$name])) {
            return;
        }
        $file = $this->ownedSqliteFixtures[$name]['file'];
        if ($file !== null) {
            $this->requireFixtureDatabase($name);
        }
        unset($this->ownedSqliteFixtures[$name]);
        $this->app['db']->purge($name);
        if ($file !== null) {
            $this->requireOwnedSqliteFile($file);
            if (! unlink($file['path']) || ! rmdir($file['directory'])) {
                throw new LogicException('Disposable test database admission refused.');
            }
        }
    }

    private function sqliteFileIdentity(string $path): array
    {
        clearstatcache(true, $path);
        $stat = lstat($path);
        if ($stat === false) {
            throw new LogicException('Disposable test database admission refused.');
        }

        return array_intersect_key($stat, array_flip(['dev', 'ino', 'mode', 'uid']));
    }

    private function requireOwnedSqliteFile(array $file): void
    {
        clearstatcache(true, $file['path']);
        clearstatcache(true, $file['directory']);
        if (is_link($file['path']) || is_link($file['directory'])
            || realpath($file['path']) !== $file['path'] || realpath($file['directory']) !== $file['directory']
            || $this->sqliteFileIdentity($file['path']) !== $file['file_identity']
            || $this->sqliteFileIdentity($file['directory']) !== $file['directory_identity']
            || ($file['file_identity']['mode'] & 0170000) !== 0100000
            || ($file['file_identity']['mode'] & 0777) !== 0600
            || ($file['directory_identity']['mode'] & 0170000) !== 0040000
            || ($file['directory_identity']['mode'] & 0777) !== 0700
            || $file['file_identity']['uid'] !== posix_geteuid() || $file['directory_identity']['uid'] !== posix_geteuid()) {
            throw new LogicException('Disposable test database admission refused.');
        }
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
