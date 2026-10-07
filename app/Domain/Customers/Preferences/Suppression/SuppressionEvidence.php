<?php

namespace App\Domain\Customers\Preferences\Suppression;

use App\Domain\Customers\Preferences\ConsentEvidence;
use App\Domain\Customers\Preferences\ConsentException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** Bounded captured-primary closure of current recipient/consent/outbox rows; no framework callbacks. */
final class SuppressionEvidence
{
    private Connection $connection;

    private PDO $primary;

    private string $database;

    private string $driver;

    private string $connectionName;

    private ?string $anchor = null;

    private int $transactionDepth;

    private array $expected = [];

    public function __construct(bool $transactionAnchor = false)
    {
        $this->connection = DB::connection();
        $primary = $this->connection->getRawPdo();
        $this->transactionDepth = $this->connection->transactionLevel();
        if (! $primary instanceof PDO || $this->transactionDepth < 1) {
            throw new ConsentException(503);
        }
        $this->primary = $primary;
        $this->database = $this->connection->getDatabaseName();
        $this->connectionName = $this->connection->getName();
        $this->driver = (string) $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME);
        $this->assertCaptured();
        if ($transactionAnchor) {
            $this->anchor = 'suppression_'.bin2hex(random_bytes(16));
            $this->primary->exec('SAVEPOINT '.$this->anchor);
        }
    }

    public function capture(string $table, string $where, array $values, int $limit = 2, ?string $order = null): array
    {
        $rows = $this->rows($table, $where, $values, $limit, $order);
        $this->expected[] = compact('table', 'where', 'values', 'limit', 'order', 'rows');

        return $rows;
    }

    public function prove(): void
    {
        $this->admit();
        foreach ($this->expected as $expected) {
            if (ConsentEvidence::normalized($this->rows($expected['table'], $expected['where'], $expected['values'], $expected['limit'], $expected['order'])) !== ConsentEvidence::normalized($expected['rows'])) {
                throw new ConsentException(503);
            }
        }
    }

    /** Refuse unresolved/replaced sources without running a PDO resolver or renewing authority. */
    public function admit(): void
    {
        $this->assertCaptured();
        if ($this->anchor === null) {
            return;
        }
        try {
            // A same-PDO commit/reopen loses this unpredictable, original-transaction marker.
            $this->primary->exec('RELEASE SAVEPOINT '.$this->anchor);
            $this->primary->exec('SAVEPOINT '.$this->anchor);
        } catch (Throwable) {
            throw new ConsentException(503);
        }
    }

    /** Keep Laravel's exception cleanup away from an unresolved or foreign physical transaction. */
    public function cleanupInterrupted(): void
    {
        if ($this->anchor === null) {
            throw new ConsentException(503);
        }
        $owned = false;
        if ($this->primary->inTransaction()) {
            try {
                $this->primary->exec('RELEASE SAVEPOINT '.$this->anchor);
                $owned = true;
            } catch (Throwable) {
                // The original transaction ended. Its replacement belongs to its caller.
            }
        }
        $cached = $this->connection->getRawPdo();
        if ($owned && $cached === $this->primary && $this->connection->transactionLevel() === 1) {
            return; // Ordinary Laravel rollback still owns this transaction and uses a cached PDO.
        }
        if ($owned) {
            $this->primary->rollBack();
        }
        // Public setPdo resets only this captured connection's framework depth; it does not
        // resolve the value or touch a replacement PDO/transaction during Laravel's catch.
        $this->connection->setPdo($cached);
    }

    private function assertCaptured(): void
    {
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || ! $this->primary->inTransaction()
            || DB::getDefaultConnection() !== $this->connectionName
            || (DB::getConnections()[$this->connectionName] ?? null) !== $this->connection
            || $this->connection->transactionLevel() !== $this->transactionDepth || $this->connection->getRawPdo() !== $this->primary || $this->connection->getDatabaseName() !== $this->database
            || ($this->driver === 'mysql' && $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->database)) {
            throw new ConsentException(503);
        }
    }

    /** Schema-qualified, shadow-refused name of a closed table; also for code-owned subqueries. */
    public function qualified(string $table): string
    {
        $allowed = ['users', 'customer_accounts', 'customer_consent_states', 'customer_consent_events', 'customer_consent_policies', ...SuppressionSchema::TABLES];
        if (! in_array($table, $allowed, true)) {
            throw new ConsentException(503);
        }
        if ($this->driver === 'sqlite') {
            $shadow = $this->primary->prepare('SELECT COUNT(*) FROM sqlite_temp_master WHERE name COLLATE NOCASE=? OR tbl_name COLLATE NOCASE=?');
            $shadow->execute([$table, $table]);
            if ((int) $shadow->fetchColumn() !== 0) {
                throw new ConsentException(503);
            }

            return 'main."'.$table.'"';
        }
        $qualified = '`'.str_replace('`', '``', $this->database).'`.`'.$table.'`';
        $schema = $this->primary->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_ASSOC);
        if (str_contains(strtoupper((string) ($schema['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
            throw new ConsentException(503);
        }

        return $qualified;
    }

    private function rows(string $table, string $where, array $values, int $limit, ?string $order): array
    {
        // Closed code-owned table/predicate/order literals; never an HTTP/query fragment.
        if ($limit < 1 || $limit > 2) {
            throw new ConsentException(503);
        }
        $qualified = $this->qualified($table);
        $statement = $this->primary->prepare('SELECT * FROM '.$qualified.' WHERE '.$where.($order ? ' ORDER BY '.$order : '').' LIMIT '.$limit.($this->driver === 'mysql' ? ' FOR UPDATE' : ''));
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
