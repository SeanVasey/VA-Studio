<?php

namespace App\Domain\Grants\Member;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;

/** Admission for the shared committed frame's concrete cached config/DB route, never a frame or grant. */
final class MemberOriginalFinishBoundary
{
    private Application $application;

    private Repository $repository;

    private DatabaseManager $manager;

    private array $baseline;

    private function __construct()
    {
        $application = Container::getInstance();
        MemberGrantException::require(get_class($application) === Application::class, 'finish_route');
        $this->application = $application;
        $instances = self::raw(Container::class, 'instances', $application);
        MemberGrantException::require(is_array($instances) && ($instances['config'] ?? null) instanceof Repository
            && get_class($instances['config']) === Repository::class && ($instances['db'] ?? null) instanceof DatabaseManager
            && get_class($instances['db']) === DatabaseManager::class, 'finish_route');
        $this->repository = $instances['config'];
        $this->manager = $instances['db'];
        $this->baseline = $this->routing();
    }

    /** Callback state may still exist during earlier proof work; this never invokes any of it. */
    public static function capture(): self
    {
        return new self;
    }

    /** Call before terminal raw proofs and immediately before/after the one combined finish. */
    public function assertCurrent(): void
    {
        MemberGrantException::require($this->routing() === $this->baseline, 'finish_route');
        // Container::resolve fires before callbacks even for cached instances. An unchanged
        // callback is still executable; identity comparison alone cannot close this boundary.
        foreach (['globalBeforeResolvingCallbacks', 'beforeResolvingCallbacks', 'buildStack', 'with', 'contextual'] as $property) {
            MemberGrantException::require(self::raw(Container::class, $property, $this->application) === [], 'finish_callbacks');
        }
        $aliases = self::raw(Container::class, 'aliases', $this->application);
        $deferred = self::raw(Application::class, 'deferredServices', $this->application);
        MemberGrantException::require(is_array($aliases) && ! array_key_exists('config', $aliases) && ! array_key_exists('db', $aliases)
            && is_array($deferred) && ! array_key_exists('config', $deferred) && ! array_key_exists('db', $deferred), 'finish_route');
    }

    /** Only the captured primary may be active in either owned proof phase. */
    public function assertFrame(PDO $primary, bool $committed): void
    {
        $this->assertCurrent();
        $found = false;
        foreach ($this->baseline['connections'] as $entry) {
            $owned = $entry['pdo'] === $primary;
            $found = $found || $owned;
            MemberGrantException::require(self::raw(Connection::class, 'transactions', $entry['connection'])
                === ($owned && ! $committed ? 1 : 0), 'changed_transaction');
            foreach (['pdo', 'readPdo', 'directPdo'] as $slot) {
                $handle = $entry[$slot];
                MemberGrantException::require($handle === null || $handle->inTransaction() === ($handle === $primary),
                    'changed_transaction');
            }
        }
        MemberGrantException::require($found, 'changed_transaction');
    }

    private function routing(): array
    {
        $instances = self::raw(Container::class, 'instances', $this->application);
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        MemberGrantException::require(Container::getInstance() === $this->application && is_array($instances)
            && ($instances['config'] ?? null) === $this->repository && ($instances['db'] ?? null) === $this->manager
            && (new ReflectionProperty(Facade::class, 'app'))->getValue() === $this->application
            && is_array($resolved) && ($resolved['db'] ?? null) === $this->manager
            && self::raw(DatabaseManager::class, 'app', $this->manager) === $this->application, 'finish_route');
        $items = self::raw(Repository::class, 'items', $this->repository);
        $connections = self::raw(DatabaseManager::class, 'connections', $this->manager);
        MemberGrantException::require(is_array($items) && is_array($items['database'] ?? null) && is_array($connections)
            && is_string($items['database']['default'] ?? null) && isset($connections[$items['database']['default']]), 'finish_route');
        $remaining = 10000;
        $result = ['database' => self::copy($items['database'], $remaining), 'connections' => []];
        foreach ($connections as $name => $connection) {
            MemberGrantException::require(is_object($connection)
                && in_array(get_class($connection), [MySqlConnection::class, SQLiteConnection::class], true), 'finish_route');
            $entry = ['connection' => $connection];
            foreach (['pdo', 'readPdo', 'directPdo'] as $property) {
                $pdo = self::raw(Connection::class, $property, $connection);
                MemberGrantException::require(($property !== 'pdo' && $pdo === null)
                    || ($pdo instanceof PDO && in_array(get_class($pdo), [PDO::class, Sqlite::class, Mysql::class], true)
                        && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]), 'finish_route');
                $entry[$property] = $pdo;
            }
            foreach (['config', 'readPdoConfig', 'directPdoConfig'] as $property) {
                $entry[$property] = self::copy(self::raw(Connection::class, $property, $connection), $remaining);
            }
            $result['connections'][$name] = $entry;
        }

        return $result;
    }

    private static function raw(string $class, string $property, object $object): mixed
    {
        return (new ReflectionProperty($class, $property))->getValue($object);
    }

    private static function copy(mixed $value, int &$remaining, int $depth = 0): mixed
    {
        MemberGrantException::require(--$remaining >= 0 && $depth <= 32, 'finish_route');
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $child) {
                $copy[$key] = self::copy($child, $remaining, $depth + 1);
            }

            return $copy;
        }
        MemberGrantException::require($value === null || is_scalar($value), 'finish_route');

        return $value;
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_finish_boundary');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_finish_boundary');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_finish_boundary');
    }

    public function __debugInfo(): array
    {
        return ['member_original_finish_boundary' => true];
    }
}
