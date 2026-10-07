<?php

namespace App\Domain\Services\Projects;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/** Permanent primary reads captured before any authority or audit callback. */
final class ServiceProjectRows
{
    private Connection $connection;

    private PDO $primary;

    private string $driver;

    private string $database;

    private string $prefix;

    private string $marker;

    public function __construct()
    {
        $this->connection = DB::connection();
        $this->primary = $this->connection->getPdo();
        $this->driver = (string) $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->database = $this->connection->getDatabaseName();
        $this->prefix = $this->connection->getTablePrefix();
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || ! $this->primary->inTransaction()) {
            throw new ServiceProjectException(503);
        }
        $this->marker = 'service_project_'.bin2hex(random_bytes(16));
        $this->primary->exec('SAVEPOINT '.$this->marker);
    }

    public function rows(string $table, array $where, string $order, ?int $limit, bool $descending): array
    {
        $this->assertCurrent();
        $grammar = $this->connection->getQueryGrammar();
        $sql = 'SELECT * FROM '.$this->table($table)
            .($where === [] ? '' : ' WHERE '.implode(' AND ', array_map(fn (string $column): string => $grammar->wrap($column).' = ?', array_keys($where))))
            .' ORDER BY '.$grammar->wrap($order).($descending ? ' DESC' : '').($limit === null ? '' : ' LIMIT '.$limit)
            .($this->driver === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $this->primary->prepare($sql);
        foreach (array_values($where) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertCurrent(): void
    {
        if (DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary
            || $this->connection->getDatabaseName() !== $this->database || $this->connection->getTablePrefix() !== $this->prefix
            || $this->connection->transactionLevel() !== 1 || ! $this->primary->inTransaction()
            || ($this->driver === 'mysql' && $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->database)) {
            throw new ServiceProjectException(503);
        }
        // A direct PDO commit/reopen destroys this private marker even if Laravel's level remains one.
        $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
        $this->primary->exec('SAVEPOINT '.$this->marker);
    }

    private function table(string $table): string
    {
        $name = $this->prefix.$table;
        if ($this->driver === 'sqlite') {
            $statement = $this->primary->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE lower(name)=lower(?)');
            $statement->execute([$name]);
            if ((int) $statement->fetchColumn() !== 0) {
                throw new ServiceProjectException(503);
            }

            return 'main."'.str_replace('"', '""', $name).'"';
        }
        $qualified = '`'.str_replace('`', '``', $this->database).'`.`'.str_replace('`', '``', $name).'`';
        $definition = (array) $this->primary->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_ASSOC);
        if (str_contains(strtoupper((string) ($definition['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
            throw new ServiceProjectException(503);
        }

        return $qualified;
    }
}
