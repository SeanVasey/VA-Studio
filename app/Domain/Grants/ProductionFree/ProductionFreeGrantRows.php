<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionClass;

/** Fixed internal SQL with bound values on the captured primary; MySQL reads lock with FOR UPDATE. */
final class ProductionFreeGrantRows
{
    public readonly CurrentRows $reader;

    private readonly PDO $pdo;

    private readonly string $driver;

    private readonly ProductionFreeGrantSchema $schema;

    private readonly int $level;

    public function __construct()
    {
        ProductionFreeGrantException::require(DB::transactionLevel() > 0, 'transaction_required');
        $this->pdo = DB::connection()->getPdo();
        $this->driver = DB::getDriverName();
        $classes = match ($this->driver) {
            'sqlite' => [PDO::class, Sqlite::class],
            'mysql' => [PDO::class, Mysql::class],
            default => [],
        };
        ProductionFreeGrantException::require(in_array($this->pdo::class, $classes, true) && (new ReflectionClass($this->pdo))->isInternal()
            && $this->pdo->inTransaction() && $this->pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class], 'changed_connection');
        $this->schema = new ProductionFreeGrantSchema;
        $this->schema->assertOwned($this->pdo);
        $this->reader = new CurrentRows($this->pdo, $this->driver);
        $this->level = DB::transactionLevel();
    }

    /** @return array<string, mixed> one sealed, verified row with its decrypted payload under `payload`, or [] */
    public function one(string $logical, string $where, array $bindings): array
    {
        $rows = $this->all($logical, $where, $bindings, 2);
        ProductionFreeGrantException::require(count($rows) <= 1, 'tampered');

        return $rows[0] ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function all(string $logical, string $where, array $bindings, int $limit): array
    {
        ProductionFreeGrantException::require($limit >= 1 && $limit <= 1000, 'schema');
        $result = [];
        foreach ($this->reader->rows($this->schema->table($logical), $where, $bindings, $limit) as $row) {
            $row['payload'] = ProductionFreeGrantRecords::verify($logical, $row);
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Ids of the newest rows, chosen in SQL by creation time then id (both descending) before any limit applies, so a
     * large result set can never hide its newest rows. MySQL locks them like the reader does.
     *
     * @return list<string>
     */
    public function newest(string $logical, string $where, array $bindings, int $limit): array
    {
        ProductionFreeGrantException::require($limit >= 1 && $limit <= 1000, 'schema');
        $statement = $this->pdo->prepare('SELECT id FROM '.$this->schema->table($logical).' WHERE '.$where
            .' ORDER BY created_at DESC, id DESC LIMIT '.$limit.($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($bindings);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function count(string $logical, string $where, array $bindings): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM '.$this->schema->table($logical).' WHERE '.$where);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /** Parent rows (users, customer_accounts) through the same locking reader. */
    public function parent(string $table, int $id): array
    {
        ProductionFreeGrantException::require(in_array($table, ['users', 'customer_accounts'], true) && $id > 0, 'schema');
        $rows = $this->reader->rows($table, 'id = ?', [$id], 2);
        ProductionFreeGrantException::require(count($rows) <= 1, 'schema');

        return $rows[0] ?? [];
    }

    /** Insert one sealed row and read it back exactly; the guards decide admission. */
    public function insert(string $logical, array $values, array $payload): array
    {
        $values['payload_ciphertext'] = ProductionFreeGrantRecords::encrypt($payload);
        $values['seal'] = ProductionFreeGrantRecords::seal($logical, $values);
        $columns = array_keys($values);
        $quote = $this->driver === 'mysql' ? '`' : '"';
        $statement = $this->pdo->prepare('INSERT INTO '.$this->schema->table($logical).' ('
            .implode(', ', array_map(fn (string $column): string => $quote.$column.$quote, $columns)).') VALUES ('.implode(', ', array_fill(0, count($values), '?')).')');
        try {
            $statement->execute(array_values($values));
        } catch (\PDOException) {
            throw new ProductionFreeGrantException('refused_by_guard');
        }
        $row = $this->one($logical, 'id = ?', [$values['id']]);
        ProductionFreeGrantException::require($row !== [] && ProductionFreeGrantRecords::strings(array_diff_key($row, ['payload' => true])) === ProductionFreeGrantRecords::strings($values), 'tampered');

        return $row;
    }

    public function assertCurrent(): void
    {
        ProductionFreeGrantException::require(DB::connection()->getPdo() === $this->pdo && $this->pdo->inTransaction()
            && DB::transactionLevel() === $this->level, 'changed_connection');
    }
}
