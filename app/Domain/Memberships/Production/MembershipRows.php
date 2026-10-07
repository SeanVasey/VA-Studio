<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use ReflectionProperty;
use Throwable;

/** One captured outer transaction per operation. A reader supplies no customer or invoice authority. */
final class MembershipRows
{
    private Container $container;

    private DatabaseManager $manager;

    private Repository $configuration;

    private Connection $connection;

    private PDO $primary;

    private string $driver;

    private string $schema;

    private string $prefix;

    private string $connectionName;

    private string $marker;

    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->manager = DB::getFacadeRoot();
        $this->configuration = $this->container->make('config');
        $this->connection = $this->manager->connection();
        $this->primary = $this->connection->getPdo();
        $this->driver = $this->connection->getDriverName();
        $this->prefix = $this->connection->getTablePrefix();
        $this->connectionName = $this->connection->getName();
        $this->schema = $this->driver === 'mysql' ? (string) $this->primary->query('SELECT DATABASE()')->fetchColumn() : 'main';
        MembershipException::require(get_class($this->manager) === DatabaseManager::class && get_class($this->configuration) === Repository::class
            && in_array($this->driver, ['sqlite', 'mysql'], true) && preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->schema) === 1
            && preg_match('/\A[a-zA-Z0-9_]*\z/D', $this->prefix) === 1, 'changed_connection');
        $this->context();
        $this->marker = 'membership_'.bin2hex(random_bytes(16));
        $this->primary->exec('SAVEPOINT '.$this->marker);
        $this->assertCurrent();
    }

    public function identity(): PDO
    {
        return $this->primary;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function current(): CurrentRows
    {
        $this->assertCurrent();

        return new CurrentRows($this->primary, $this->driver);
    }

    public function assertCurrent(): void
    {
        try {
            $this->context();
            // A commit/rollback, including direct PDO commit/reopen, destroys this original marker.
            // Releasing/recreating it never rolls back consumer writes or bypasses Laravel events.
            $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            $this->primary->exec('SAVEPOINT '.$this->marker);
            (new MembershipSchema)->assertOwned($this->primary);
            $this->context();
        } catch (Throwable) {
            throw new MembershipException('changed_primary_or_schema');
        }
    }

    /** Fixed server SQL only; caller HTTP values belong in bound parameters, never SQL fragments. */
    public function rows(string $table, string $where, array $bindings, int $limit): array
    {
        MembershipException::require($limit >= 1 && $limit <= MembershipPolicy::MAX_EVENTS + 1, 'technical_bound');
        $reader = $this->current();
        $rows = $reader->rows((new MembershipSchema)->table($table), $where, $bindings, $limit);
        $this->context();

        return $rows;
    }

    public function one(string $table, string $where, array $bindings): array
    {
        $rows = $this->rows($table, $where, $bindings, 2);
        MembershipException::require(count($rows) <= 1, 'ambiguous_source');

        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $bindings): void
    {
        $this->assertCurrent();
        $statement = $this->primary->prepare($sql);
        $statement->execute($bindings);
        MembershipException::require($statement->rowCount() === 1, 'ambiguous_effect');
        $this->context();
    }

    /** Reflection and captured PDO only. No facade/container resolution after terminal raw proof. */
    private function context(): void
    {
        $instances = self::property($this->container, Container::class, 'instances');
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $connections = self::property($this->manager, DatabaseManager::class, 'connections');
        $items = self::property($this->configuration, Repository::class, 'items');
        MembershipException::require(Container::getInstance() === $this->container
            && ($instances['db'] ?? null) === $this->manager && ($resolved['db'] ?? null) === $this->manager
            && ($instances['config'] ?? null) === $this->configuration
            && ($connections[$this->connectionName] ?? null) === $this->connection
            && ($items['database']['default'] ?? null) === $this->connectionName
            && self::property($this->connection, Connection::class, 'pdo') === $this->primary
            && self::property($this->connection, Connection::class, 'transactions') === 1
            && self::property($this->connection, Connection::class, 'tablePrefix') === $this->prefix && $this->primary->inTransaction()
            && $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $this->driver, 'changed_connection');
        if ($this->driver === 'mysql') {
            MembershipException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->schema, 'changed_schema');
        }
    }

    private static function property(object $object, string $class, string $name): mixed
    {
        return (new ReflectionProperty($class, $name))->getValue($object);
    }
}
