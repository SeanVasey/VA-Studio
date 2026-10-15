<?php

namespace App\Domain\Grants\PaidTax;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use PDO;
use PDOException;
use Throwable;

/** An older exact consumer root; prepared reads never cycle a marker underneath the younger SourceV2. */
final class PaidTaxRows
{
    public readonly PDO $primary;

    public readonly Connection $connection;

    public readonly Repository $configuration;

    public readonly string $environment;

    private readonly PaidTaxOwnedFrame $frame;

    private readonly string $database;

    private readonly string $driver;

    private array $definitions;

    private bool $ended = false;

    private bool $finished = false;

    private bool $committed = false;

    private ?CurrentRows $sourceReader = null;

    public function __construct(private readonly PaidTaxRuntime $runtime)
    {
        $runtime->prove(1);
        $this->primary = $runtime->primary;
        $this->connection = $runtime->connection;
        $this->configuration = $runtime->configuration;
        $this->environment = $runtime->environment;
        $this->driver = $runtime->driver;
        $this->database = $this->driver === 'mysql' ? $runtime->database : 'main';
        PaidTaxException::require(preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->database) === 1);
        $this->frame = PaidTaxOwnedFrame::capture($this->connection, $this->primary);
        try {
            // Installation callbacks occur after the original cleanup marker and before final raw comparisons.
            $this->held(function (): void {
                foreach (array_keys(PaidTaxSchema::specs()) as $table) {
                    $this->primary->query('SELECT 1 FROM '.$this->qualifiedTable($table).' LIMIT 0')->fetchAll();
                }
                PaidTaxSchema::assertInstalled();
            }, metadata: true);
            $this->definitions = $this->schemaDefinitions(1);
            $weak = \WeakReference::create($this);
            $events = $this->connection->getEventDispatcher();
            PaidTaxException::require($events !== null);
            $events->listen(TransactionCommitted::class, static function ($event) use ($weak): void {
                $rows = $weak->get();
                if ($rows !== null && $event::class === TransactionCommitted::class && $event->connection === $rows->connection && ! $rows->ended) {
                    $rows->ended = true;
                    $rows->committed = true;
                }
            });
            $events->listen(TransactionRolledBack::class, static function ($event) use ($weak): void {
                $rows = $weak->get();
                if ($rows !== null && $event::class === TransactionRolledBack::class && $event->connection === $rows->connection && ! $rows->ended) {
                    $rows->ended = true;
                }
            });
        } catch (Throwable $error) {
            $this->frame->abort();
            throw $error;
        }
    }

    public function assertCurrent(): void
    {
        $this->runtime->prove(1);
        PaidTaxException::require(! $this->ended && ! $this->finished);
        $this->frame->proveHeld();
    }

    public function callbackPhase(): void
    {
        PaidTaxException::require(app() === $this->runtime->application && app('db') === $this->runtime->manager
            && app('config') === $this->configuration && app()->environment() === $this->environment);
        $this->assertCurrent();
    }

    public function committedCallbackPhase(): void
    {
        PaidTaxException::require(app() === $this->runtime->application && app('db') === $this->runtime->manager
            && app('config') === $this->configuration && app()->environment() === $this->environment);
        $this->assertCommitted();
    }

    public function admitSource(): CurrentRows
    {
        $this->assertCurrent();
        PaidTaxException::require($this->sourceReader === null);
        $this->sourceReader = new CurrentRows($this->primary, $this->driver);

        return $this->sourceReader;
    }

    public function current(): CurrentRows
    {
        $this->assertCurrent();

        return $this->sourceReader ?? new CurrentRows($this->primary, $this->driver);
    }

    public function finish(): void
    {
        $this->assertCurrent();
        PaidTaxException::require($this->sourceReader !== null && $this->schemaDefinitions(1) === $this->definitions);
        $this->finished = true;
        // Leave every younger source marker intact. SourceV2 owns the irreversible terminal release.
        $this->runtime->prove(1);
    }

    public function assertPrepared(): void
    {
        $this->runtime->prove(1);
        PaidTaxException::require(! $this->ended && $this->finished && $this->sourceReader !== null);
        $this->frame->proveHeld();
        PaidTaxException::require($this->schemaDefinitions(1) === $this->definitions);
    }

    public function assertCommitted(): void
    {
        $this->runtime->prove(0);
        PaidTaxException::require($this->ended && $this->finished && $this->committed);
        // This callback flag alone is never a positive result: the command closes its exact SourceV2 receipt too.
        PaidTaxException::require($this->schemaDefinitions(0) === $this->definitions);
    }

    public function abort(): void
    {
        $this->frame->abort();
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
        PaidTaxException::require(preg_match('/\A[a-z][a-z0-9_]*\z/D', $logical) === 1);
        if ($this->driver === 'sqlite') {
            $statement = $this->primary->prepare('SELECT 1 FROM temp.sqlite_schema WHERE name = ? COLLATE NOCASE OR tbl_name = ? COLLATE NOCASE LIMIT 1');
            $statement->execute([$logical, $logical]);
            PaidTaxException::require($statement->fetchColumn() === false);
            $statement = $this->primary->prepare('SELECT name, type FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE');
            $statement->execute([$logical]);
            PaidTaxException::require($statement->fetchAll(PDO::FETCH_NUM) === [[$logical, 'table']]);

            return 'main."'.$logical.'"';
        }
        $table = '`'.$this->database.'`.`'.$logical.'`';
        // SHOW CREATE observes the opened object, including its current transactional engine and any temp shadow.
        $shown = $this->primary->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
        PaidTaxException::require(is_array($shown) && is_string($shown[1] ?? null)
            && str_starts_with($shown[1], 'CREATE TABLE `'.$logical.'` (')
            && preg_match('/\n\) ENGINE=InnoDB(?: |$)/', $shown[1]) === 1);

        return $table;
    }

    private function schemaDefinitions(int $depth): array
    {
        $result = [];
        foreach (array_keys(PaidTaxSchema::specs()) as $logical) {
            $this->runtime->prove($depth); // Guard autocommit before EVERY closed table/catalog observation.
            $table = $this->qualifiedTable($logical);
            if ($this->driver === 'sqlite') {
                $statement = $this->primary->prepare('SELECT type, name, tbl_name, sql FROM main.sqlite_schema WHERE tbl_name = ? ORDER BY type, name');
                $statement->execute([$logical]);
                $result[$logical] = $statement->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $shown = $this->primary->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                $shown[1] = preg_replace('/(\n\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= DEFAULT CHARSET=)/', '$1', $shown[1]);
                $statement = $this->primary->prepare('SELECT TRIGGER_NAME, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT, SQL_MODE, DEFINER, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME');
                $statement->execute([$this->database, $logical]);
                $result[$logical] = [$shown, $statement->fetchAll(PDO::FETCH_ASSOC)];
            }
            $this->runtime->prove($depth);
        }

        return $result;
    }

    public function preparedRows(string $logical, string $where, array $bindings, int $limit): array
    {
        $this->assertPrepared();

        return $this->select($logical, $where, $bindings, $limit, false);
    }

    public function rows(string $logical, string $where, array $bindings, int $limit = 1001, bool $closed = false): array
    {
        $closed ? $this->assertCommitted() : $this->assertCurrent();
        $operation = fn (): array => $this->select($logical, $where, $bindings, $limit, ! $closed);

        return $closed ? $operation() : $this->held($operation);
    }

    private function select(string $logical, string $where, array $bindings, int $limit, bool $lock): array
    {
        PaidTaxException::require($limit >= 1 && $limit <= 1001 && count($bindings) === substr_count($where, '?'));
        $this->runtime->prove($lock ? 1 : ($this->finished && $this->ended ? 0 : 1));
        $statement = $this->primary->prepare('SELECT * FROM '.$this->qualifiedTable($logical).' WHERE '.$where.' ORDER BY id LIMIT '.$limit
            .($lock && $this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function one(string $logical, string $where, array $bindings, bool $closed = false): array
    {
        $rows = $this->rows($logical, $where, $bindings, 2, $closed);
        PaidTaxException::require(count($rows) <= 1);

        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $bindings): void
    {
        $this->held(function () use ($sql, $bindings): void {
            $statement = $this->primary->prepare($sql);
            $statement->execute($bindings);
            PaidTaxException::require($statement->rowCount() === 1);
        });
    }

    private function held(callable $operation, bool $metadata = false): mixed
    {
        $this->assertCurrent();
        $variable = $metadata ? 'lock_wait_timeout' : 'innodb_lock_wait_timeout';
        $wait = $this->driver === 'mysql' ? (int) $this->primary->query('SELECT @@session.'.$variable)->fetchColumn() : null;
        try {
            if ($wait !== null) {
                $this->primary->exec('SET SESSION '.$variable.' = '.min($wait, 5));
            }
            $result = $operation();
            $this->assertCurrent();

            return $result;
        } catch (PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new PaidTaxException;
            }
            throw $error;
        } finally {
            if ($wait !== null) {
                $this->primary->exec('SET SESSION '.$variable.' = '.$wait);
            }
        }
    }
}
