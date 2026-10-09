<?php

namespace App\Domain\Customers\ProductionIdentity;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/** Captured writer; all internal terminal SQL bypasses QueryExecuted/model callbacks. */
final readonly class IdentityDatabase
{
    public Connection $connection;

    public PDO $primary;

    public string $driver;

    public IdentityRows $rows;

    public function __construct()
    {
        $this->connection = DB::connection();
        $this->primary = $this->connection->getPdo();
        $this->driver = $this->connection->getDriverName();
        if (! in_array($this->driver, ['sqlite', 'mysql'], true)) {
            throw new IdentityException;
        }
        $this->rows = new IdentityRows($this->primary, $this->driver);
    }

    public function close(bool $transaction): void
    {
        if (DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary
            || $this->primary->inTransaction() !== $transaction
            || $this->connection->transactionLevel() !== ($transaction ? 1 : 0)) {
            throw new IdentityException;
        }
        foreach (DB::getConnections() as $connection) {
            if ($connection !== $this->connection && ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction())) {
                throw new IdentityException;
            }
        }
        $this->rows->assertPermanent();
    }

    public function insert(string $table, array $values): int
    {
        $this->rows->assertTable($table);
        $columns = array_keys($values);
        $statement = $this->primary->prepare('INSERT INTO '.$this->rows->table($table).' (`'.implode('`,`', $columns).'`) VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
        $statement->execute(array_values($values));

        return (int) $this->primary->lastInsertId();
    }

    public function same(array $first, array $second): void
    {
        if ($this->normalize($first) !== $this->normalize($second)) {
            throw new IdentityException;
        }
    }

    private function normalize(array $value): array
    {
        foreach ($value as &$entry) {
            $entry = is_array($entry) ? $this->normalize($entry) : ($entry === null ? null : (string) $entry);
        }
        ksort($value);

        return $value;
    }
}
