<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/** Captured transaction, permanent qualified raw rows and a private final-only marker. */
final class PaidGrantRows
{
    public readonly PDO $primary;

    public readonly Connection $connection;

    public readonly Repository $configuration;

    public readonly string $environment;

    private readonly Application $application;

    private readonly DatabaseManager $manager;

    private readonly string $driver;

    private readonly string $database;

    private readonly string $name;

    private readonly string $prefix;

    private readonly string $marker;

    private readonly string $keyHash;

    private readonly string $configuredEnvironment;

    private readonly Closure $rawBindings;

    private readonly array $originalBindings;

    private bool $ended = false;

    private bool $finished = false;

    private bool $committed = false;

    private ?CurrentRows $sourceReader = null;

    public function __construct()
    {
        $application = Container::getInstance();
        PaidGrantException::require($application instanceof Application);
        $this->application = $application;
        $raw = Closure::bind(function (): array {
            return [$this->instances['config'] ?? null, $this->instances['db'] ?? null, $this->instances['env'] ?? null,
                $this->aliases['config'] ?? null, $this->aliases['db'] ?? null, $this->aliases['env'] ?? null,
                $this->bindings['env'] ?? null];
        }, $application, Container::class);
        PaidGrantException::require($raw instanceof Closure);
        $this->rawBindings = $raw;
        $this->originalBindings = $raw();
        $configuration = $this->originalBindings[0];
        $manager = $this->originalBindings[1];
        PaidGrantException::require($configuration instanceof Repository && $configuration::class === Repository::class
            && $manager instanceof DatabaseManager && $manager::class === DatabaseManager::class
            && is_string(PaidGrantConfiguration::read($configuration, 'app.env')));
        $this->manager = $manager;
        $this->configuration = $configuration;
        $name = PaidGrantConfiguration::read($configuration, 'database.default');
        PaidGrantException::require(is_string($name));
        $this->name = $name;
        $connection = $this->manager->getConnections()[$this->name] ?? null;
        PaidGrantException::require($connection instanceof Connection
            && in_array($connection::class, [MySqlConnection::class, SQLiteConnection::class], true));
        $this->connection = $connection;
        $primary = $connection->getRawPdo();
        PaidGrantException::require($primary instanceof PDO && in_array($primary::class, [PDO::class, 'Pdo\\Mysql', 'Pdo\\Sqlite'], true)
            && (new ReflectionClass($primary))->isInternal() && $primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]);
        $this->primary = $primary;
        $this->driver = $this->connection->getDriverName();
        $this->prefix = $this->connection->getTablePrefix();
        $this->database = $this->driver === 'mysql' ? (string) $this->primary->query('SELECT DATABASE()')->fetchColumn() : 'main';
        $this->configuredEnvironment = PaidGrantConfiguration::read($configuration, 'app.env');
        $this->keyHash = hash('sha256', (string) PaidGrantConfiguration::read($configuration, 'app.key'));
        // Producer's retained reader currently names unprefixed tables; never silently bind a different graph.
        PaidGrantException::require(in_array($this->driver, ['sqlite', 'mysql'], true) && $this->prefix === ''
            && preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->database) === 1);
        $this->assertCurrent();
        $this->marker = 'paid_consumer_'.bin2hex(random_bytes(16));
        $this->primary->exec('SAVEPOINT '.$this->marker);
        // Laravel may keep env as an unshared scalar binding. Evaluate it only after original-frame capture.
        $this->environment = $this->application->environment();
        PaidGrantException::require($this->environment === $this->configuredEnvironment);
        $weak = \WeakReference::create($this);
        $events = $this->connection->getEventDispatcher();
        PaidGrantException::require($events !== null);
        $events->listen(TransactionCommitted::class, function ($event) use ($weak): void {
            $rows = $weak->get();
            if ($rows !== null && ! $rows->ended && $event->connection === $rows->connection) {
                $rows->ended = true;
                $rows->committed = true;
            }
        });
        $events->listen(TransactionRolledBack::class, function ($event) use ($weak): void {
            $rows = $weak->get();
            if ($rows !== null && ! $rows->ended && $event->connection === $rows->connection) {
                $rows->ended = true;
            }
        });
    }

    /** Any container/provider resolution must precede terminal raw actor/source reads. */
    public function callbackPhase(): void
    {
        PaidGrantException::require(app() === $this->application && app('db') === $this->manager
            && app('config') === $this->configuration && app()->environment() === $this->environment);
        $this->assertCurrent();
    }

    public function committedCallbackPhase(): void
    {
        PaidGrantException::require(app() === $this->application && app('db') === $this->manager
            && app('config') === $this->configuration && app()->environment() === $this->environment);
        $this->assertCommitted();
    }

    private function identityCurrent(): void
    {
        PaidGrantException::require(Container::getInstance() === $this->application
            && ($this->rawBindings)() === $this->originalBindings
            && PaidGrantConfiguration::read($this->configuration, 'database.default') === $this->name
            && ($this->manager->getConnections()[$this->name] ?? null) === $this->connection
            && $this->connection->getRawPdo() === $this->primary
            && $this->connection->getDriverName() === $this->driver && $this->connection->getTablePrefix() === $this->prefix
            && $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $this->driver
            && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
            && PaidGrantConfiguration::read($this->configuration, 'app.env') === $this->configuredEnvironment
            && hash_equals($this->keyHash, hash('sha256', (string) PaidGrantConfiguration::read($this->configuration, 'app.key'))));
        if ($this->driver === 'mysql') {
            PaidGrantException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database);
        }
    }

    public function assertCurrent(): void
    {
        $this->identityCurrent();
        PaidGrantException::require(! $this->ended && ! $this->finished && $this->connection->transactionLevel() === 1 && $this->primary->inTransaction());
    }

    /** Prove the earliest frame after current/historical callbacks, before the younger producer anchor exists. */
    public function admitSource(): CurrentRows
    {
        $this->assertCurrent();
        PaidGrantException::require($this->sourceReader === null);
        $this->proveMarker();
        $this->sourceReader = new CurrentRows($this->primary, $this->driver);

        return $this->sourceReader;
    }

    private function proveMarker(): void
    {
        $this->assertCurrent();
        try {
            $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            // Retain the original consumer frame for failure cleanup. Admission occurs
            // before the producer anchor; releasing this older marker afterward would expire it.
            $this->primary->exec('SAVEPOINT '.$this->marker);
        } catch (Throwable) {
            throw new PaidGrantException;
        }
        $this->identityCurrent();
    }

    /** Once admitted, leave the producer's younger anchor intact through its final commit observer. */
    public function finish(): void
    {
        $this->assertCurrent();
        if ($this->sourceReader === null) {
            // Metadata-only frames have no producer anchor and still prove their original frame.
            $this->proveMarker();
        }
        $this->finished = true;
        $this->identityCurrent();
    }

    public function assertCommitted(): void
    {
        $this->identityCurrent();
        PaidGrantException::require($this->ended && $this->committed && $this->finished
            && $this->connection->transactionLevel() === 0 && ! $this->primary->inTransaction());
    }

    /** Read-only admission on the original prepared frame; the producer subsequently proves its private anchor. */
    public function assertPrepared(): void
    {
        $this->identityCurrent();
        PaidGrantException::require(! $this->ended && $this->finished && $this->sourceReader !== null
            && $this->connection->transactionLevel() === 1 && $this->primary->inTransaction());
    }

    /** Failure cleanup only for the still-present original marker; never resolve a replacement PDO. */
    public function abort(): void
    {
        try {
            if ($this->committed || ! $this->primary->inTransaction()) {
                return;
            }
            try {
                $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            } catch (Throwable) {
                // Original commit/rollback/reopen expired this frame; do not adopt another transaction.
                return;
            }
            $this->primary->rollBack();
            $this->ended = true;
        } finally {
            $this->forgetRefusedFrameRecords();
        }
    }

    /**
     * A commit-time refusal lowers Laravel's depth without notifying the transactions manager, so the refused
     * frame's pending record (and any after-commit work registered inside it) would otherwise run on the next
     * unrelated commit. Paid commands start outside every transaction and own the only frame on this
     * connection, so clearing its records at depth zero discards nothing that belongs to a caller.
     */
    private function forgetRefusedFrameRecords(): void
    {
        if ($this->connection->transactionLevel() !== 0) {
            return;
        }
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($this->connection);
        if ($manager instanceof DatabaseTransactionsManager) {
            $manager->rollback($this->connection->getName(), 0);
        }
    }

    public function current(): CurrentRows
    {
        $this->assertCurrent();

        return $this->sourceReader ?? new CurrentRows($this->primary, $this->driver);
    }

    public function identity(): PDO
    {
        return $this->primary;
    }

    public function table(string $logical, bool $closed = false): string
    {
        $closed ? $this->assertCommitted() : $this->assertCurrent();

        return $this->qualifiedTable($logical);
    }

    private function qualifiedTable(string $logical): string
    {
        PaidGrantException::require(preg_match('/\A[a-z][a-z0-9_]*\z/D', $logical) === 1);
        if ($this->driver === 'sqlite') {
            $query = $this->primary->prepare('SELECT 1 FROM sqlite_temp_master WHERE name = ? COLLATE NOCASE OR tbl_name = ? COLLATE NOCASE LIMIT 1');
            $query->execute([$logical, $logical]);
            PaidGrantException::require($query->fetchColumn() === false);
            $query = $this->primary->prepare('SELECT name, type FROM main.sqlite_master WHERE name = ? COLLATE NOCASE');
            $query->execute([$logical]);
            PaidGrantException::require($query->fetchAll(PDO::FETCH_NUM) === [[$logical, 'table']]);

            return 'main."'.$logical.'"';
        }
        $table = '`'.$this->database.'`.`'.$logical.'`';
        $dictionary = $this->primary->prepare('SELECT TABLE_SCHEMA, TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $dictionary->execute([$this->database, $logical]);
        PaidGrantException::require($dictionary->fetchAll(PDO::FETCH_NUM) === [[$this->database, $logical, 'BASE TABLE']]);
        $shown = $this->primary->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
        PaidGrantException::require(is_array($shown) && str_starts_with((string) $shown[1], 'CREATE TABLE '));

        return $table;
    }

    /** All compared records were already held before preparation. This final closure acquires no new row lock. */
    public function preparedRows(string $logical, string $where, array $bindings, int $limit): array
    {
        $this->assertPrepared();
        PaidGrantException::require($limit >= 1 && $limit <= 1001);
        $query = $this->primary->prepare('SELECT * FROM '.$this->qualifiedTable($logical).' WHERE '.$where.' ORDER BY id LIMIT '.$limit);
        $query->execute($bindings);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function rows(string $table, string $where, array $bindings, int $limit = 1001, bool $closed = false): array
    {
        PaidGrantException::require($limit >= 1 && $limit <= 1001);
        $physical = $this->table($table, $closed);
        $sql = 'SELECT * FROM '.$physical.' WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .($this->driver === 'mysql' && ! $closed ? ' FOR UPDATE' : '');
        $query = $this->primary->prepare($sql);
        $query->execute($bindings);

        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public function one(string $table, string $where, array $bindings, bool $closed = false): array
    {
        $rows = $this->rows($table, $where, $bindings, 2, $closed);
        PaidGrantException::require(count($rows) <= 1);

        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $bindings): void
    {
        $this->assertCurrent();
        $query = $this->primary->prepare($sql);
        $query->execute($bindings);
        PaidGrantException::require($query->rowCount() === 1);
    }
}
