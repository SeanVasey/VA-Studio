<?php

namespace App\Domain\Customers\ProductionIdentity;

use PDO;
use Throwable;

/** Fixed permanent plain SELECT only. No FOR UPDATE, transaction, identity mint, or write API. */
final readonly class IdentityHistoricalPlainRows
{
    private PDO $primary;

    private string $driver;

    private string $schema;

    private function __construct(private IdentityOriginalCommitWitness $witness)
    {
        $reader = $witness->reader();
        $this->primary = $reader->identityPrimary();
        $this->driver = $reader->identityDriver();
        $this->schema = $witness->schema();
        $this->assertPermanent();
    }

    public static function fromWitness(IdentityOriginalCommitWitness $witness): self
    {
        return new self($witness);
    }

    public function assertPermanent(): void
    {
        try {
            $this->witness->assertReading();
            [$present] = (new IdentityMigrationOwnership)->inspect($this->primary, $this->driver);
            if (in_array(false, $present, true)) {
                throw new IdentityException;
            }
            $this->witness->assertReading();
        } catch (Throwable) {
            throw new IdentityException('historical_permanent_primary_required');
        }
    }

    public function rows(string $table, string $where, array $bindings, ?int $limit = null): array
    {
        $allowed = match ($table) {
            'users', 'customer_accounts', 'production_identity_challenges' => [['id = ?', 1]],
            'production_identity_origins' => [['id = ?', 1], ['account_id = ?', 2]],
            'production_identity_verifications' => [['id = ?', 1], ['origin_id = ?', 129]],
            default => [],
        };
        if (! in_array([$where, $limit], $allowed, true) || count($bindings) !== 1 || ! is_int($bindings[0]) || $bindings[0] < 1) {
            throw new IdentityException('historical_fixed_read_required');
        }
        $this->assertTable($table);
        $statement = $this->primary->prepare('SELECT * FROM '.$this->table($table).' WHERE '.$where.' ORDER BY id LIMIT '.$limit);
        $statement->execute($bindings);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->witness->assertReading();

        return $rows;
    }

    public function one(string $table, int $id): array
    {
        return $this->rows($table, 'id = ?', [$id], 1)[0] ?? [];
    }

    private function assertTable(string $table): void
    {
        try {
            $this->witness->assertReading();
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
                $statement = $this->primary->prepare('SELECT TABLE_NAME,TABLE_TYPE,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND LOWER(TABLE_NAME)=?');
                $statement->execute([$this->schema, $table]);
                $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
                $shown = $this->primary->query('SHOW CREATE TABLE '.$this->table($table))->fetch(PDO::FETCH_ASSOC);
                if (count($rows) !== 1 || $rows[0]['TABLE_NAME'] !== $table || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE' || $rows[0]['ENGINE'] !== 'InnoDB'
                    || ! is_string($shown['Create Table'] ?? null) || str_starts_with($shown['Create Table'], 'CREATE TEMPORARY TABLE ')) {
                    throw new IdentityException;
                }
            }
        } catch (Throwable) {
            throw new IdentityException('historical_permanent_primary_required');
        }
    }

    private function table(string $table): string
    {
        return '`'.str_replace('`', '``', $this->schema).'`.`'.$table.'`';
    }
}
