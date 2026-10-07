<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/** One original owned command frame; never adopts a reopened transaction or rolls back to its marker. */
final class CheckoutCommandFrame
{
    private string $marker;

    private int $deadlineNs;

    private ?CheckoutCommandCommitDispatcher $observer = null;

    private bool $committed = false;

    private array $bindings;

    private array $aliases;

    private array $configuration;

    private string $database;

    private string $name;

    private function __construct(
        private readonly Connection $connection,
        private readonly PDO $primary,
        private readonly string $driver,
        private readonly Container $container,
        private readonly Repository $repository,
        private readonly DatabaseManager $manager,
    ) {
        $this->marker = 'checkout_original_'.bin2hex(random_bytes(16));
        $this->deadlineNs = hrtime(true) + 300_000_000_000;
        $this->bindings = $this->rawBindings();
        $this->aliases = $this->rawAliases();
        $this->configuration = $this->configuration();
        $this->name = $this->configuration['database']['default'];
        $this->database = $connection->getDatabaseName();
        $this->prove(1);
        $primary->exec('SAVEPOINT '.$this->marker);
    }

    public static function capture(Connection $connection, PDO $primary, string $driver): self
    {
        CheckoutException::require($connection::class === ($driver === 'mysql' ? MySqlConnection::class : SQLiteConnection::class), 'write_frame');
        $container = Container::getInstance();
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
        CheckoutException::require(is_array($instances), 'write_frame');
        $repository = $instances['config'] ?? null;
        $manager = $instances['db'] ?? null;
        CheckoutException::require($repository instanceof Repository && $repository::class === Repository::class
            && $manager instanceof DatabaseManager && $manager::class === DatabaseManager::class, 'write_frame');

        return new self($connection, $primary, $driver, $container, $repository, $manager);
    }

    /** Install only for NEW review/order writes. Replay/read/reconcile frames have no observer. */
    public function register(CheckoutWriteAdmission $admission): void
    {
        CheckoutException::require($this->observer === null && $admission->belongsTo($this), 'write_frame');
        $this->proveAnchor();
        $this->observer = CheckoutCommandCommitDispatcher::capture($this, $admission);
    }

    public function prove(int $depth): void
    {
        $this->provePdo();
        CheckoutException::require(in_array($depth, [0, 1], true) && hrtime(true) < $this->deadlineNs
            && Container::getInstance() === $this->container && $this->rawBindings() === $this->bindings
            && $this->rawAliases() === $this->aliases && $this->configuration() === $this->configuration, 'write_frame');
        $connections = $this->manager->getConnections();
        CheckoutException::require(($connections[$this->name] ?? null) === $this->connection
            && $this->connection::class === ($this->driver === 'mysql' ? MySqlConnection::class : SQLiteConnection::class)
            && $this->connection->getRawPdo() === $this->primary && $this->connection->getDriverName() === $this->driver
            && $this->connection->getDatabaseName() === $this->database && $this->connection->getTablePrefix() === ''
            && $this->connection->transactionLevel() === $depth && $this->primary->inTransaction() === ($depth === 1), 'write_frame');
        if ($this->driver === 'mysql') {
            CheckoutException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database, 'write_frame');
        }
        if ($this->observer !== null) {
            CheckoutException::require($this->connection->getEventDispatcher() === $this->observer, 'write_frame');
        }
    }

    public function provePdo(): void
    {
        $classes = $this->driver === 'mysql' ? [PDO::class, Mysql::class] : [PDO::class, Sqlite::class];
        CheckoutException::require(in_array($this->driver, ['sqlite', 'mysql'], true)
            && in_array($this->primary::class, $classes, true) && (new ReflectionClass($this->primary))->isInternal()
            && $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $this->driver
            && $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'write_frame');
    }

    public function proveAnchor(): void
    {
        $this->prove(1);
        try {
            $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            $this->primary->exec('SAVEPOINT '.$this->marker);
        } catch (Throwable) {
            CheckoutException::require(false, 'write_frame');
        }
        $this->prove(1);
    }

    public function capDeadline(int $deadlineNs): void
    {
        $this->deadlineNs = min($this->deadlineNs, $deadlineNs);
        $this->prove(1);
    }

    /** Laravel can decrement to zero while a committing listener leaves PDO physically active. */
    public function abort(): void
    {
        if ($this->committed || ! $this->primary->inTransaction()) {
            return;
        }
        try {
            // Cleanup uses only this captured internal PDO and its unpredictable original marker.
            // It does not require the withdrawn policy, resolve another writer or touch a replacement frame.
            $classes = $this->driver === 'mysql' ? [PDO::class, Mysql::class] : [PDO::class, Sqlite::class];
            if (! in_array($this->primary::class, $classes, true) || ! (new ReflectionClass($this->primary))->isInternal()) {
                return;
            }
            $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            $this->primary->rollBack();
        } catch (Throwable) {
            // A missing marker belongs to an expired/reopened frame; leave it untouched.
        }
    }

    public function markCommitted(): void
    {
        $this->committed = true;
    }

    public function restore(): void
    {
        $this->observer?->restore();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function primary(): PDO
    {
        return $this->primary;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_checkout_command_frame'];
    }

    public function __serialize(): never
    {
        throw new \LogicException('Checkout command frames cannot be serialized.');
    }

    private function rawBindings(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        CheckoutException::require(is_array($instances), 'write_frame');

        return [$instances['config'] ?? null, $instances['db'] ?? null];
    }

    private function rawAliases(): array
    {
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($this->container);
        CheckoutException::require(is_array($aliases), 'write_frame');

        return $aliases;
    }

    private function configuration(): array
    {
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->repository);
        CheckoutException::require(is_array($items), 'write_frame');
        $result = [];
        foreach (['app', 'database', 'production_checkout', 'production-customer-identity'] as $parent) {
            $value = $items[$parent] ?? [];
            CheckoutException::require(is_array($value), 'write_frame');
            self::requirePlain($value);
            $result[$parent] = $value;
        }
        CheckoutException::require(is_string($result['database']['default'] ?? null) && $result['database']['default'] !== '', 'write_frame');

        return $result;
    }

    private static function requirePlain(array $values): void
    {
        foreach ($values as $value) {
            CheckoutException::require(is_array($value) || is_scalar($value) || $value === null, 'write_frame');
            if (is_array($value)) {
                self::requirePlain($value);
            }
        }
    }
}
