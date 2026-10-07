<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\Preferences\ConsentEvidence;
use App\Domain\Customers\Preferences\ConsentException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;

/** Bounded captured-primary closure of current recipient/consent/outbox rows; no framework callbacks. */
final class SuppressionEvidence
{
    private Connection $connection;

    private PDO $primary;

    private string $database;

    private string $driver;

    private array $expected = [];

    public function __construct()
    {
        $this->connection = DB::connection();
        $this->primary = $this->connection->getPdo();
        $this->database = $this->connection->getDatabaseName();
        $this->driver = (string) $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertCaptured();
    }

    public function capture(string $table, string $where, array $values, int $limit = 2, ?string $order = null): array
    {
        $rows = $this->rows($table, $where, $values, $limit, $order);
        $this->expected[] = compact('table', 'where', 'values', 'limit', 'order', 'rows');

        return $rows;
    }

    public function prove(): void
    {
        $this->assertCaptured();
        foreach ($this->expected as $expected) {
            if (ConsentEvidence::normalized($this->rows($expected['table'], $expected['where'], $expected['values'], $expected['limit'], $expected['order'])) !== ConsentEvidence::normalized($expected['rows'])) {
                throw new ConsentException(503);
            }
        }
    }

    private function assertCaptured(): void
    {
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || ! $this->primary->inTransaction()
            || DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary || $this->connection->getDatabaseName() !== $this->database
            || ($this->driver === 'mysql' && $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->database)) {
            throw new ConsentException(503);
        }
    }

    private function rows(string $table, string $where, array $values, int $limit, ?string $order): array
    {
        // Closed code-owned table/predicate/order literals; never an HTTP/query fragment.
        $allowed = ['users', 'customer_accounts', 'customer_consent_states', 'customer_consent_events', 'customer_consent_policies', ...SuppressionSchema::TABLES];
        if (! in_array($table, $allowed, true) || $limit < 1 || $limit > 2) {
            throw new ConsentException(503);
        }
        if ($this->driver === 'sqlite') {
            $shadow = $this->primary->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE name COLLATE NOCASE=? OR tbl_name COLLATE NOCASE=?');
            $shadow->execute([$table, $table]);
            if ((int) $shadow->fetchColumn() !== 0) {
                throw new ConsentException(503);
            }
            $qualified = 'main."'.$table.'"';
        } else {
            $qualified = '`'.str_replace('`', '``', $this->database).'`.`'.$table.'`';
            $schema = $this->primary->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($schema['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                throw new ConsentException(503);
            }
        }
        $statement = $this->primary->prepare('SELECT * FROM '.$qualified.' WHERE '.$where.($order ? ' ORDER BY '.$order : '').' LIMIT '.$limit.($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
