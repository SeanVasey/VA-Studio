<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;
use Throwable;

/** One captured outer transaction per operation. A reader supplies no customer or invoice authority. */
final class MembershipRows
{
    /**
     * Functions the bundled SQLite registers as extensions (FTS3/FTS5/R-Tree), reported with builtin = 0.
     * Owned membership SQL never calls them. Any other non-builtin function, or a non-builtin entry that
     * shares a built-in name (an application override such as lower(), max() or count()), is refused.
     */
    private const SQLITE_EXTENSION_FUNCTIONS = ['bm25', 'fts3_tokenizer', 'fts5', 'fts5_source_id', 'highlight', 'match',
        'matchinfo', 'offsets', 'optimize', 'rtreecheck', 'rtreedepth', 'rtreenode', 'snippet'];

    private const SQLITE_COLLATIONS = ['BINARY', 'NOCASE', 'RTRIM'];

    /** A held, table-less running statement. SQLite refuses to replace a built-in or existing function/collation while one is active. */
    private const SQLITE_PIN = 'WITH RECURSIVE membership_pin(x) AS (SELECT 1 UNION ALL SELECT x + 1 FROM membership_pin LIMIT 2) SELECT x FROM membership_pin';

    private const FETCH_MODES = [PDO::FETCH_BOTH, PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_OBJ];

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

    private ?PDOStatement $pin = null;

    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->manager = DB::getFacadeRoot();
        $this->configuration = $this->container->make('config');
        $this->connection = $this->manager->connection();
        $this->primary = $this->connection->getPdo();
        $this->nativeStatements();
        if ($this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            // Taken before any other SQL: from here on, SQLite itself refuses a replacement of a
            // built-in function or collation (BINARY/NOCASE/RTRIM) on this handle with SQLITE_BUSY.
            $this->pin = $this->primary->prepare(self::SQLITE_PIN);
            MembershipException::require($this->pin->execute() === true, 'changed_primary');
        }
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

    public function __destruct()
    {
        $this->pin?->closeCursor();
    }

    /**
     * The pin is a statement shared by reference and closed in __destruct. A clone would share it, and destroying the clone
     * would release the live frame's pin (review R-1: SQLite then admitted a BINARY collation replacement inside the live frame).
     * A frame is therefore never copied. PHP still runs the destructor of the half-built copy after this throws, so the copy
     * drops its reference first and has nothing left to close; the original keeps its own reference.
     */
    public function __clone(): void
    {
        $this->pin = null;

        throw new MembershipException('clone_refused');
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
        $this->nativeStatements();
        $instances = self::property($this->container, Container::class, 'instances');
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $connections = self::property($this->manager, DatabaseManager::class, 'connections');
        $items = self::property($this->configuration, Repository::class, 'items');
        MembershipException::require(is_array($instances) && is_array($resolved) && is_array($connections)
            && is_array($items) && is_array($items['database'] ?? null)
            && is_string($items['database']['default'] ?? null), 'changed_connection');
        MembershipException::require(Container::getInstance() === $this->container
            && ($instances['db'] ?? null) === $this->manager && ($resolved['db'] ?? null) === $this->manager
            && ($instances['config'] ?? null) === $this->configuration
            && ($connections[$this->connectionName] ?? null) === $this->connection
            && ($items['database']['default'] ?? null) === $this->connectionName
            && self::property($this->connection, Connection::class, 'pdo') === $this->primary
            && self::property($this->connection, Connection::class, 'transactions') === 1
            && self::property($this->connection, Connection::class, 'tablePrefix') === $this->prefix && $this->primary->inTransaction()
            && $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $this->driver, 'changed_connection');
        // SQLite cannot exclude a built-in collation replaced before capture (no catalog shows it),
        // so it serves synthetic local/testing rehearsal only, never verified production evidence.
        $policy = $items['production-memberships'] ?? null;
        MembershipException::require($this->driver === 'mysql'
            || (is_array($policy) && ($policy['provenance'] ?? null) !== IdentityPolicy::PRODUCTION), 'provenance_driver');
        if ($this->driver === 'mysql') {
            MembershipException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->schema, 'changed_schema');
        }
    }

    private static function property(object $object, string $class, string $name): mixed
    {
        return (new ReflectionProperty($class, $name))->getValue($object);
    }

    /** Raw metadata/authority SQL cannot execute an application statement subclass. */
    private function nativeStatements(): void
    {
        MembershipException::require(in_array(get_class($this->primary), [PDO::class, Sqlite::class, Mysql::class], true)
            && $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && in_array($this->primary->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), self::FETCH_MODES, true), 'changed_primary');
        $this->sqliteCallbacks();
    }

    /**
     * SQLite lets an application register PHP functions, aggregates and collations on the handle, and
     * they run inside ordinary metadata SQL (lower(name) = lower(?)). PDO cannot enumerate them, so
     * read SQLite's own catalogs before any other SQL. Neither catalog read has a WHERE clause, so it
     * calls no function and compares no text; filtering happens in PHP.
     */
    private function sqliteCallbacks(): void
    {
        if ($this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            return;
        }
        try {
            $functions = $this->primary->query('SELECT name, builtin FROM pragma_function_list')->fetchAll(PDO::FETCH_NUM);
            $collations = $this->primary->query('PRAGMA collation_list')->fetchAll(PDO::FETCH_NUM);
        } catch (Throwable) {
            // pragma_function_list compiled out or unreadable: no SQLite reader is admitted.
            throw new MembershipException('sqlite_callback_catalog');
        }
        $builtin = [];
        $application = [];
        foreach ($functions as $function) {
            MembershipException::require(is_array($function) && count($function) === 2 && is_string($function[0]), 'sqlite_callback_catalog');
            if ((string) $function[1] === '1') {
                $builtin[strtolower($function[0])] = true;
            } else {
                $application[] = strtolower($function[0]);
            }
        }
        MembershipException::require($builtin !== [], 'sqlite_callback_catalog');
        foreach ($application as $name) {
            MembershipException::require(in_array($name, self::SQLITE_EXTENSION_FUNCTIONS, true) && ! isset($builtin[$name]), 'sqlite_application_function');
        }
        $names = [];
        foreach ($collations as $collation) {
            MembershipException::require(is_array($collation) && count($collation) === 2 && is_string($collation[1]), 'sqlite_callback_catalog');
            $names[] = $collation[1];
        }
        sort($names);
        MembershipException::require($names === self::SQLITE_COLLATIONS, 'sqlite_application_collation');
    }
}
