<?php

namespace App\Domain\SupportAttachments;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Support\Facades\DB;
use PDO;

/** Capture before any framework callback. All attachment SQL uses this primary directly. */
final class AttachmentRows
{
    private PDO $primary;
    private string $driver;
    private string $prefix;
    private string $database;

    public function __construct()
    {
        $connection = DB::connection();
        $this->primary = $connection->getPdo();
        $this->driver = $connection->getDriverName();
        $this->prefix = $connection->getTablePrefix();
        $this->database = $this->driver === 'mysql' ? (string) $this->primary->query('SELECT DATABASE()')->fetchColumn() : 'main';
        AttachmentException::require(in_array($this->driver, ['sqlite', 'mysql'], true)
            && preg_match('/\A[a-zA-Z0-9_]*\z/D', $this->prefix) === 1
            && preg_match('/\A[a-zA-Z0-9_]+\z/D', $this->database) === 1);
    }

    public function identity(): PDO { return $this->primary; }
    public function driver(): string { return $this->driver; }
    public function current(): CurrentRows { $this->assertCurrent(); return new CurrentRows($this->primary, $this->driver); }

    public function assertCurrent(): void
    {
        AttachmentException::require(DB::transactionLevel() > 0 && DB::connection()->getPdo() === $this->primary
            && DB::connection()->getTablePrefix() === $this->prefix && $this->primary->inTransaction());
        if ($this->driver === 'mysql') {
            AttachmentException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database);
        }
    }

    public function table(string $table): string
    {
        AttachmentException::require(preg_match('/\A[a-z][a-z0-9_]*\z/D', $table) === 1);
        $name = $this->prefix.$table;
        if ($this->driver === 'sqlite') {
            $statement = $this->primary->prepare('SELECT 1 FROM sqlite_temp_master WHERE name = ? LIMIT 1');
            $statement->execute([$name]);
            AttachmentException::require($statement->fetchColumn() === false);

            return 'main."'.$name.'"';
        }
        // MySQL temporary tables can shadow qualified tables too. SHOW CREATE must prove the persistent table.
        $definition = $this->primary->query('SHOW CREATE TABLE `'.$this->database.'`.`'.$name.'`')->fetch(PDO::FETCH_NUM);
        AttachmentException::require(is_array($definition) && ! str_contains(strtoupper((string) $definition[1]), 'CREATE TEMPORARY TABLE'));

        return '`'.$this->database.'`.`'.$name.'`';
    }

    public function rows(string $table, string $where, array $bindings, int $limit): array
    {
        AttachmentException::require($limit >= 1 && $limit <= 101);
        return $this->current()->rows($this->table($table), $where, $bindings, $limit);
    }

    public function one(string $table, string $where, array $bindings): array
    {
        $rows = $this->rows($table, $where, $bindings, 2);
        AttachmentException::require(count($rows) <= 1);
        return $rows[0] ?? [];
    }

    public function execute(string $sql, array $bindings): void
    {
        $this->assertCurrent();
        $statement = $this->primary->prepare($sql);
        $statement->execute($bindings);
        AttachmentException::require($statement->rowCount() === 1);
    }
}
