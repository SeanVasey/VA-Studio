<?php

namespace App\Domain\Grants\Member;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;

/** Captured resolved handles only. No getter, resolver, SQL or transaction cleanup runs here. */
final class MemberOriginalIoFrame implements JsonSerializable
{
    private Container $container;

    private Repository $repository;

    private DatabaseManager $manager;

    private array $configuration;

    private array $connections;

    private array $handles;

    private function __construct()
    {
        $this->container = Container::getInstance();
        $instances = self::raw(Container::class, 'instances', $this->container);
        MemberGrantException::require(is_array($instances)
            && isset($instances['config'], $instances['db'])
            && get_class($instances['config']) === Repository::class
            && get_class($instances['db']) === DatabaseManager::class, 'io_frame');
        $this->repository = $instances['config'];
        $this->manager = $instances['db'];
        $this->configuration = $this->configuration();
        $connections = self::raw(DatabaseManager::class, 'connections', $this->manager);
        MemberGrantException::require(is_array($connections) && $connections !== []
            && is_string($this->configuration['database']['default'] ?? null)
            && isset($connections[$this->configuration['database']['default']]), 'io_frame');
        $this->connections = [];
        $this->handles = [];
        foreach ($connections as $name => $connection) {
            MemberGrantException::require(is_string($name) && $name !== '' && is_object($connection)
                && in_array(get_class($connection), [MySqlConnection::class, SQLiteConnection::class], true), 'io_frame');
            $this->connections[$name] = $connection;
            $this->handles[$name] = $this->connection($connection);
        }
        $this->assertStillIdle();
    }

    public static function captureIdle(): self
    {
        return new self;
    }

    public function assertStillIdle(): void
    {
        MemberGrantException::require($this->configuration() === $this->configuration
            && self::raw(DatabaseManager::class, 'connections', $this->manager) === $this->connections, 'io_frame');
        foreach ($this->connections as $name => $connection) {
            MemberGrantException::require($this->connection($connection) === $this->handles[$name], 'io_frame');
        }
    }

    private function configuration(): array
    {
        MemberGrantException::require(Container::getInstance() === $this->container, 'io_frame');
        $instances = self::raw(Container::class, 'instances', $this->container);
        $facades = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        MemberGrantException::require(is_array($instances) && ($instances['config'] ?? null) === $this->repository
            && ($instances['db'] ?? null) === $this->manager
            && (new ReflectionProperty(Facade::class, 'app'))->getValue() === $this->container
            && is_array($facades) && ($facades['db'] ?? null) === $this->manager
            && self::raw(DatabaseManager::class, 'app', $this->manager) === $this->container, 'io_frame');
        $items = self::raw(Repository::class, 'items', $this->repository);
        MemberGrantException::require(is_array($items), 'io_frame');
        $remaining = 20000;

        return self::copy($items, $remaining);
    }

    private function connection(Connection $connection): array
    {
        MemberGrantException::require(self::raw(Connection::class, 'transactions', $connection) === 0, 'io_transaction');
        $write = self::raw(Connection::class, 'pdo', $connection);
        $read = self::raw(Connection::class, 'readPdo', $connection);
        $direct = self::raw(Connection::class, 'directPdo', $connection);
        // Lazy handles must be resolved during setup, before taking this complete snapshot.
        MemberGrantException::require($write instanceof PDO && ($read === null || $read instanceof PDO)
            && ($direct === null || $direct instanceof PDO), 'io_frame');
        $driver = get_class($connection) === SQLiteConnection::class ? 'sqlite' : 'mysql';
        foreach ([$write, $read, $direct] as $pdo) {
            if ($pdo === null) {
                continue;
            }
            MemberGrantException::require(in_array(get_class($pdo), [PDO::class, Sqlite::class, Mysql::class], true)
                && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $driver
                && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class], 'io_frame');
            MemberGrantException::require(! $pdo->inTransaction(), 'io_transaction');
        }
        $remaining = 10000;
        $config = self::raw(Connection::class, 'config', $connection);
        $readConfig = self::raw(Connection::class, 'readPdoConfig', $connection);
        $directConfig = self::raw(Connection::class, 'directPdoConfig', $connection);
        MemberGrantException::require(is_array($config) && is_array($readConfig) && is_array($directConfig)
            && ($config['driver'] ?? null) === $driver, 'io_frame');

        return [$write, $read, $direct, self::copy($config, $remaining), self::copy($readConfig, $remaining),
            self::copy($directConfig, $remaining)];
    }

    private static function raw(string $class, string $property, object $object): mixed
    {
        return (new ReflectionProperty($class, $property))->getValue($object);
    }

    /** Detach references without serialization, magic methods or object callbacks. */
    private static function copy(mixed $value, int &$remaining, int $depth = 0): mixed
    {
        MemberGrantException::require(--$remaining >= 0 && $depth <= 32, 'io_frame');
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::copy($child, $remaining, $depth + 1);
            }

            return $copy;
        }
        MemberGrantException::require($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value)), 'io_frame');

        return $value;
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_io_frame');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_io_frame');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_io_frame');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_io_frame');
    }

    public function __debugInfo(): array
    {
        return ['member_original_io_frame' => true];
    }
}
