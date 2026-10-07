<?php

namespace App\Domain\Grants\Free;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use PDO;

/** Captured primary, qualified persistent tables and fixed internal SQL; no model/query callbacks. */
final class FreeGrantRows
{
    private readonly PDO $primary;

    private readonly string $driver;

    private readonly string $prefix;

    private readonly string $database;

    public function __construct()
    {
        $connection = DB::connection();
        $this->primary = $connection->getPdo();
        $this->driver = $connection->getDriverName();
        $this->prefix = $connection->getTablePrefix();
        $this->database = $this->driver === 'mysql' ? (string) $this->primary->query('SELECT DATABASE()')->fetchColumn() : 'main';
        FreeGrantException::require(in_array($this->driver, ['sqlite', 'mysql'], true)
            && preg_match('/\A[a-zA-Z0-9_]{0,16}\z/D', $this->prefix) === 1
            && preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->database) === 1);
    }

    public function assertCurrent(): void
    {
        FreeGrantException::require(DB::transactionLevel() > 0 && DB::connection()->getPdo() === $this->primary
            && DB::connection()->getTablePrefix() === $this->prefix && $this->primary->inTransaction());
        if ($this->driver === 'mysql') {
            FreeGrantException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database);
        }
    }

    public function identity(): PDO
    {
        return $this->primary;
    }

    public function current(): CurrentRows
    {
        $this->assertCurrent();

        return new CurrentRows($this->primary, $this->driver);
    }

    public function table(string $table): string
    {
        $this->assertCurrent();
        FreeGrantException::require(preg_match('/\A[a-z][a-z0-9_]*\z/D', $table) === 1);
        $name = $this->prefix.$table;
        if ($this->driver === 'sqlite') {
            $statement = $this->primary->prepare('SELECT 1 FROM sqlite_temp_master WHERE name = ? COLLATE NOCASE LIMIT 1');
            $statement->execute([$name]);
            FreeGrantException::require($statement->fetchColumn() === false);

            return 'main."'.$name.'"';
        }
        $definition = $this->primary->query('SHOW CREATE TABLE `'.$this->database.'`.`'.$name.'`')->fetch(PDO::FETCH_NUM);
        FreeGrantException::require(is_array($definition) && ! str_contains(strtoupper((string) $definition[1]), 'CREATE TEMPORARY TABLE'));

        return '`'.$this->database.'`.`'.$name.'`';
    }

    public function rows(string $table, string $where, array $bindings, int $limit = 1001): array
    {
        FreeGrantException::require($limit >= 1 && $limit <= 1001);

        return $this->current()->rows($this->table($table), $where, $bindings, $limit);
    }

    public function one(string $table, string $where, array $bindings): array
    {
        $rows = $this->rows($table, $where, $bindings, 2);
        FreeGrantException::require(count($rows) <= 1);

        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $bindings): void
    {
        $this->assertCurrent();
        $statement = $this->primary->prepare($sql);
        $statement->execute($bindings);
        FreeGrantException::require($statement->rowCount() === 1);
    }
}
