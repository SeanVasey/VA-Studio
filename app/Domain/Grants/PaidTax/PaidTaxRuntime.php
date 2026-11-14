<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionTaxCheckout\FreshTaxCheckoutPolicy;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionProperty;

/** Original raw application/connection purpose; capture before callback-capable locators and services. */
final class PaidTaxRuntime
{
    public readonly Application $application;

    public readonly Repository $configuration;

    public readonly DatabaseManager $manager;

    public readonly Connection $connection;

    public readonly PDO $primary;

    public readonly string $driver;

    public readonly string $database;

    public readonly string $environment;

    private readonly string $name;

    private readonly string $keyHash;

    private readonly array $bindings;

    private readonly array $connectionMetadata;

    private readonly array $purpose;

    private ?FreshTaxCheckoutPolicy $taxPurpose = null;

    private function __construct(private readonly PaidTaxDeadline $budget)
    {
        $application = Container::getInstance();
        PaidTaxException::require($application::class === Application::class);
        $this->application = $application;
        $maps = $this->maps();
        [$instances, $bindings, $aliases] = $maps;
        $configuration = $instances['config'] ?? null;
        $manager = $instances['db'] ?? null;
        PaidTaxException::require($configuration instanceof Repository && $configuration::class === Repository::class
            && $manager instanceof DatabaseManager && $manager::class === DatabaseManager::class);
        $this->configuration = $configuration;
        $this->manager = $manager;
        $this->environment = self::environment($instances['env'] ?? null, $bindings['env'] ?? null);
        $this->bindings = [$configuration, $manager, $instances['env'] ?? null, $bindings['env'] ?? null, $aliases];
        $name = PaidTaxConfiguration::read($configuration, 'database.default');
        PaidTaxException::require(is_string($name));
        $this->name = $name;
        $connections = $this->connections();
        $connection = $connections[$name] ?? null;
        PaidTaxException::require($connection instanceof Connection);
        $this->connection = $connection;
        $this->connectionMetadata = self::metadata($connection);
        $this->driver = $connection->getDriverName();
        $this->database = $connection->getDatabaseName();
        PaidTaxException::require($connection->getTablePrefix() === '' && in_array($this->driver, ['sqlite', 'mysql'], true));
        $primary = $connection->getRawPdo();
        PaidTaxException::require($primary instanceof PDO);
        $this->primary = $primary;
        $key = PaidTaxConfiguration::read($configuration, 'app.key');
        PaidTaxException::require(is_string($key) && $key !== ''
            && PaidTaxConfiguration::read($configuration, 'app.env') === $this->environment);
        $this->keyHash = hash('sha256', $key);
        $purpose = PaidTaxConfiguration::read($configuration, 'paid-tax-grants');
        PaidTaxException::require(is_array($purpose));
        $this->purpose = $purpose;
        PaidTaxException::require(($purpose['rehearsal_enabled'] ?? null) === true
            && ($purpose['operative_enabled'] ?? null) === false, 404);
        $this->prove(0);
    }

    public static function capture(PaidTaxDeadline $budget): self
    {
        $runtime = new self($budget);
        // Callback-capable enablement resolves only after original raw dependencies were admitted.
        $runtime->taxPurpose = FreshTaxCheckoutPolicy::capture();
        $runtime->prove(0);

        return $runtime;
    }

    public function prove(int $depth): void
    {
        $this->budget->proveCurrent();
        [$instances, $bindings, $aliases] = $this->maps();
        PaidTaxException::require(Container::getInstance() === $this->application
            && [$instances['config'] ?? null, $instances['db'] ?? null, $instances['env'] ?? null, $bindings['env'] ?? null, $aliases] === $this->bindings
            && self::environment($instances['env'] ?? null, $bindings['env'] ?? null) === $this->environment
            && PaidTaxConfiguration::read($this->configuration, 'database.default') === $this->name
            && ($this->connections()[$this->name] ?? null) === $this->connection
            && self::metadata($this->connection) === $this->connectionMetadata);
        $key = PaidTaxConfiguration::read($this->configuration, 'app.key');
        PaidTaxException::require(is_string($key) && hash_equals($this->keyHash, hash('sha256', $key))
            && PaidTaxConfiguration::read($this->configuration, 'app.env') === $this->environment
            && PaidTaxConfiguration::read($this->configuration, 'paid-tax-grants') === $this->purpose);
        PaidTaxException::require(in_array($depth, [0, 1], true) && $this->connection->getRawPdo() === $this->primary
            && $this->connection->transactionLevel() === $depth && $this->primary->inTransaction() === ($depth === 1)
            && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);
        if ($this->driver === 'mysql') {
            if ($depth === 0) {
                PaidTaxException::require((int) $this->primary->query('SELECT @@session.autocommit')->fetchColumn() === 1);
            }
            PaidTaxException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database);
        } else {
            PaidTaxException::require(in_array(strtolower((string) $this->primary->query('PRAGMA main.journal_mode')->fetchColumn()),
                ['delete', 'truncate', 'persist', 'memory', 'wal'], true));
        }
        $this->taxPurpose?->prove();
        $this->budget->proveCurrent();
    }

    public function outsideTransactions(): void
    {
        $this->prove(0);
        foreach ($this->connections() as $connection) {
            PaidTaxException::require($connection->transactionLevel() === 0 && ! $connection->getRawPdo()->inTransaction(), 409);
        }
    }

    private function maps(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->application);
        $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getValue($this->application);
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($this->application);
        $facades = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        PaidTaxException::require(is_array($instances) && is_array($bindings) && is_array($aliases) && is_array($facades));
        if (($instances['env'] ?? null) === null) {
            PaidTaxException::require(is_array($bindings['env'] ?? null));
        }
        PaidTaxException::require(! array_key_exists('db', $facades) || $facades['db'] === ($instances['db'] ?? null));
        PaidTaxException::require(! array_key_exists('config', $facades) || $facades['config'] === ($instances['config'] ?? null));

        return [$instances, $bindings, $aliases];
    }

    private function connections(): array
    {
        $connections = (new ReflectionProperty(DatabaseManager::class, 'connections'))->getValue($this->manager);
        PaidTaxException::require(is_array($connections));
        foreach ($connections as $connection) {
            PaidTaxException::require($connection instanceof Connection);
            self::metadata($connection);
            $primary = $connection->getRawPdo();
            PaidTaxException::require($primary instanceof PDO && in_array($primary::class, [PDO::class, 'Pdo\\Mysql', 'Pdo\\Sqlite'], true)
                && (new ReflectionClass($primary))->isInternal()
                && $primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
                && $primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $connection->getDriverName());
        }

        return $connections;
    }

    private static function metadata(Connection $connection): array
    {
        PaidTaxException::require(in_array($connection::class, [MySqlConnection::class, SQLiteConnection::class], true));
        $configuration = (new ReflectionProperty(Connection::class, 'config'))->getValue($connection);
        $database = (new ReflectionProperty(Connection::class, 'database'))->getValue($connection);
        $prefix = (new ReflectionProperty(Connection::class, 'tablePrefix'))->getValue($connection);
        PaidTaxException::require(is_array($configuration) && is_string($configuration['driver'] ?? null)
            && is_string($configuration['name'] ?? null) && is_string($configuration['database'] ?? null)
            && is_string($configuration['prefix'] ?? null) && is_string($database) && is_string($prefix));

        return [$configuration['driver'], $configuration['name'], $database, $prefix];
    }

    private static function environment(mixed $instance, mixed $binding): string
    {
        $environment = $instance;
        if ($environment === null) {
            PaidTaxException::require(is_array($binding));
            $concrete = $binding['concrete'] ?? null;
            PaidTaxException::require($concrete instanceof Closure);
            $reflection = new ReflectionFunction($concrete);
            $method = new ReflectionMethod(Container::class, 'offsetSet');
            PaidTaxException::require($reflection->getFileName() === $method->getFileName()
                && $reflection->getStartLine() >= $method->getStartLine() && $reflection->getEndLine() <= $method->getEndLine());
            $environment = $reflection->getStaticVariables()['value'] ?? null;
        }
        PaidTaxException::require(in_array($environment, ['local', 'testing'], true), 404);

        return $environment;
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new \LogicException('Tax consumer runtime cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Tax consumer runtime cannot be deserialized.');
    }
}
