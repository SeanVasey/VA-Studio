<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Permanent captured-primary identity SQL. The public commerce reader supplies only its exact PDO/driver seam. */
final readonly class IdentityRows
{
    private Connection $connection;

    private string $schema;

    public function __construct(private PDO $primary, private string $driver, private ?IdentityCommittedFrame $committedFrame = null)
    {
        $this->connection = DB::connection();
        $this->schema = $driver === 'sqlite' ? 'main' : $this->connection->getDatabaseName();
        $this->assertPermanent();
    }

    public static function from(CurrentRows $reader): self
    {
        return new self($reader->identityPrimary(), $reader->identityDriver());
    }

    public static function committed(IdentityCommittedFrame $frame): self
    {
        $reader = $frame->reader();

        return new self($reader->identityPrimary(), $reader->identityDriver(), $frame);
    }

    public function assertPermanent(): void
    {
        try {
            // Resolve/refuse all connection callbacks before any ordinary getPdo() in this explicit mode.
            $this->committedFrame?->assertActive();
            if (! in_array($this->driver, ['sqlite', 'mysql'], true) || DB::connection() !== $this->connection
                || $this->connection->getPdo() !== $this->primary || $this->connection->getDriverName() !== $this->driver
                || $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->driver) {
                throw new IdentityException;
            }
            if ($this->committedFrame === null && ($this->connection->transactionLevel() > 1 || $this->primary->inTransaction() !== ($this->connection->transactionLevel() === 1))) {
                throw new IdentityException;
            }
            foreach (DB::getConnections() as $connection) {
                if ($connection !== $this->connection && ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction())) {
                    throw new IdentityException;
                }
            }
            if ($this->driver === 'mysql' && ($this->connection->getDatabaseName() !== $this->schema
                || $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->schema)) {
                throw new IdentityException;
            }
            // Install-time admission is insufficient: callbacks may introduce temporary aliases after commit.
            [$present] = (new IdentityMigrationOwnership)->inspect($this->primary, $this->driver);
            if (in_array(false, $present, true)) {
                throw new IdentityException;
            }
            // Audit writes are also permanent; the migration's parent inspection covers the other parents.
            if ($this->driver === 'sqlite') {
                $temporary = $this->primary->query("SELECT name,tbl_name FROM sqlite_temp_master WHERE LOWER(name)='audit_events' OR LOWER(tbl_name)='audit_events'")->fetchAll(PDO::FETCH_ASSOC);
                $audit = $this->primary->query("SELECT name,type FROM main.sqlite_master WHERE LOWER(name)='audit_events'")->fetchAll(PDO::FETCH_ASSOC);
                if ($temporary !== [] || count($audit) !== 1 || $audit[0] !== ['name' => 'audit_events', 'type' => 'table']) {
                    throw new IdentityException;
                }
            } else {
                $audit = $this->primary->query('SHOW CREATE TABLE '.$this->table('audit_events'))->fetch(PDO::FETCH_ASSOC);
                if (! is_string($audit['Create Table'] ?? null) || str_starts_with($audit['Create Table'], 'CREATE TEMPORARY TABLE ')) {
                    throw new IdentityException;
                }
            }
        } catch (Throwable) {
            throw new IdentityException('permanent_primary_required');
        }
    }

    /** Target metadata is checked on every raw read/write; full floor is closed at entry/terminal boundaries. */
    public function assertTable(string $table): void
    {
        try {
            $this->committedFrame?->assertActive();
            $qualified = $this->table($table);
            if (DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary
                || $this->connection->getDriverName() !== $this->driver || $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->driver) {
                throw new IdentityException;
            }
            if ($this->driver === 'sqlite') {
                $statement = $this->primary->prepare('SELECT name,type,sql FROM main.sqlite_master WHERE LOWER(name)=?');
                $statement->execute([$table]);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $temporary = $this->primary->prepare('SELECT name FROM sqlite_temp_master WHERE LOWER(name)=? OR LOWER(tbl_name)=?');
                $temporary->execute([$table, $table]);
                if ($temporary->fetchAll(PDO::FETCH_ASSOC) !== [] || count($rows) !== 1 || $rows[0]['name'] !== $table || $rows[0]['type'] !== 'table'
                    || (isset(IdentitySchema::definitions()[$table]) && $rows[0]['sql'] !== IdentitySchema::tableSql($table, $this->driver))) {
                    throw new IdentityException;
                }
            } else {
                if ($this->connection->getDatabaseName() !== $this->schema || $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->schema) {
                    throw new IdentityException;
                }
                $statement = $this->primary->prepare('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND LOWER(TABLE_NAME)=?');
                $statement->execute([$this->schema, $table]);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $shown = $this->primary->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_ASSOC);
                if (count($rows) !== 1 || $rows[0]['TABLE_NAME'] !== $table || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $rows[0]['ENGINE'] !== 'InnoDB'
                    || ! is_string($shown['Create Table'] ?? null) || str_starts_with($shown['Create Table'], 'CREATE TEMPORARY TABLE ')) {
                    throw new IdentityException;
                }
            }
        } catch (Throwable) {
            throw new IdentityException('permanent_primary_required');
        }
    }

    public function table(string $table): string
    {
        if (! in_array($table, ['users', 'customer_accounts', 'quote_owners', 'audit_events', ...array_keys(IdentitySchema::definitions())], true)) {
            throw new IdentityException;
        }

        return '`'.str_replace('`', '``', $this->schema).'`.`'.$table.'`';
    }

    public function rows(string $table, string $where, array $bindings, ?int $limit = null): array
    {
        $this->assertTable($table);
        $statement = $this->primary->prepare('SELECT * FROM '.$this->table($table).' WHERE '.$where.' ORDER BY id'.($limit === null ? '' : ' LIMIT '.$limit)
            .($this->driver === 'mysql' && $this->committedFrame === null ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function one(string $table, int $id): array
    {
        return $this->rows($table, 'id = ?', [$id], 1)[0] ?? [];
    }
}
