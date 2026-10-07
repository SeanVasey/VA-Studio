<?php

namespace App\Domain\Grants\Paid;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Application;
use PDO;
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

    private bool $ended = false;

    private bool $finished = false;

    private bool $committed = false;

    public function __construct()
    {
        $this->application = app();
        $this->manager = app('db');
        $this->configuration = app('config');
        $this->connection = $this->manager->connection();
        $this->primary = $this->connection->getPdo();
        $this->name = $this->manager->getDefaultConnection();
        $this->driver = $this->connection->getDriverName();
        $this->prefix = $this->connection->getTablePrefix();
        $this->database = $this->driver === 'mysql' ? (string) $this->primary->query('SELECT DATABASE()')->fetchColumn() : 'main';
        $this->environment = $this->application->environment();
        $this->keyHash = hash('sha256', (string) $this->configuration->get('app.key'));
        // Producer's retained reader currently names unprefixed tables; never silently bind a different graph.
        PaidGrantException::require(in_array($this->driver, ['sqlite', 'mysql'], true) && $this->prefix === ''
            && preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->database) === 1);
        $this->assertCurrent();
        $this->marker = 'paid_consumer_'.bin2hex(random_bytes(16));
        $this->primary->exec('SAVEPOINT '.$this->marker);
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
            && $this->manager->getDefaultConnection() === $this->name
            && ($this->manager->getConnections()[$this->name] ?? null) === $this->connection
            && $this->connection->getRawPdo() === $this->primary
            && $this->connection->getDriverName() === $this->driver && $this->connection->getTablePrefix() === $this->prefix
            && hash_equals($this->keyHash, hash('sha256', (string) $this->configuration->get('app.key'))));
        if ($this->driver === 'mysql') {
            PaidGrantException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database);
        }
    }

    public function assertCurrent(): void
    {
        $this->identityCurrent();
        PaidGrantException::require(! $this->ended && ! $this->finished && $this->connection->transactionLevel() === 1 && $this->primary->inTransaction());
    }

    /** After the producer's LAST proof only: releasing this older marker expires its younger marker. */
    public function finish(): void
    {
        $this->assertCurrent();
        try {
            $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            // Keep our final marker until actual commit so failed framework commits can
            // still roll back this original frame without resolving a replacement PDO.
            $this->primary->exec('SAVEPOINT '.$this->marker);
        } catch (Throwable) {
            throw new PaidGrantException;
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

    /** Failure cleanup only for the still-present original marker; never resolve a replacement PDO. */
    public function abort(): void
    {
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
    }

    public function current(): CurrentRows
    {
        $this->assertCurrent();

        return new CurrentRows($this->primary, $this->driver);
    }

    public function identity(): PDO
    {
        return $this->primary;
    }

    public function table(string $logical, bool $closed = false): string
    {
        $closed ? $this->assertCommitted() : $this->assertCurrent();
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
        $shown = $this->primary->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
        PaidGrantException::require(is_array($shown) && str_starts_with((string) $shown[1], 'CREATE TABLE '));

        return $table;
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
