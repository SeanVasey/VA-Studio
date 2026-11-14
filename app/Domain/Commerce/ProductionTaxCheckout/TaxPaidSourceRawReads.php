<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use LogicException;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use ReflectionClass;

/** Fixed SQL infrastructure. Expected facts stay private in authenticated SourceV2; this grants no authority. */
final readonly class TaxPaidSourceRawReads implements \JsonSerializable
{
    private function __construct(private TaxPaidReadContext $context) {}

    public static function capture(TaxPaidReadContext $context): self
    {
        $context->proveHeld();
        $reads = new self($context);
        if ($context->driver() === 'mysql') {
            $reads->pinNativeTables();
        }
        $context->proveHeld();

        return $reads;
    }

    public function rows(string $kind, string $where, array $bindings, int $depth): array
    {
        $allowed = match ($kind) {
            'order' => ['id = ?', 'public_id = ?', 'buyer_origin_id = ? AND request_key = ?'],
            'request' => ['id = ?', 'public_id = ?', 'order_id = ?', 'idempotency_key = ?'],
            'binding' => ['id = ?', 'public_id = ?', 'request_id = ?', 'account_id = ? AND funds_mode = ? AND provider_session_id = ?'],
            'reviewed' => ['id = ?', 'public_id = ?', 'request_id = ?', 'order_id = ?', 'binding_id = ?',
                'account_id = ? AND funds_mode = ? AND provider_session_id = ?', 'account_id = ? AND funds_mode = ? AND provider_payment_id = ?'],
            default => [],
        };
        CheckoutException::require(in_array($where, $allowed, true) && count($bindings) === substr_count($where, '?'), 'committed_read_frame');
        $read = function () use ($kind, $where, $bindings, $depth): array {
            $this->context->prove($depth);
            $statement = $this->context->primary()->prepare('SELECT * FROM '.$this->qualified(TaxCheckoutSchema::TABLES[$kind])
                .' WHERE '.$where.' ORDER BY id LIMIT 2'.($depth === 1 && $this->context->driver() === 'mysql' ? ' FOR UPDATE' : ''));
            $statement->execute($bindings);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            $this->context->prove($depth);

            return $rows;
        };

        return $depth === 1 ? $this->context->held($read) : $this->context->closed($read);
    }

    public function definitions(int $depth): array
    {
        $result = [];
        foreach (TaxCheckoutSchema::TABLES as $table) {
            $this->context->prove($depth);
            $primary = $this->context->primary();
            if ($this->context->driver() === 'mysql') {
                $row = $primary->query('SHOW CREATE TABLE '.$this->qualified($table))->fetch(PDO::FETCH_NUM);
                CheckoutException::require(is_array($row) && is_string($row[1] ?? null)
                    && str_starts_with($row[1], 'CREATE TABLE '), 'committed_read_frame');
                // Only the actual allocator table option is excluded, never arbitrary comments or ENGINE text.
                $row[1] = preg_replace('/(\n\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= DEFAULT CHARSET=)/', '$1', $row[1]);
                $result[$table] = $row;
            } else {
                $statement = $primary->prepare('SELECT name, type, sql FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE');
                $statement->execute([$table]);
                $row = $statement->fetchAll(PDO::FETCH_ASSOC);
                CheckoutException::require(count($row) === 1 && $row[0]['name'] === $table && $row[0]['type'] === 'table', 'committed_read_frame');
                $this->context->prove($depth);
                $temporary = $primary->prepare('SELECT name, tbl_name FROM temp.sqlite_schema WHERE name = ? COLLATE NOCASE OR tbl_name = ? COLLATE NOCASE');
                $temporary->execute([$table, $table]);
                CheckoutException::require($temporary->fetchAll(PDO::FETCH_ASSOC) === [], 'temporary_shadow');
                $result[$table] = $row;
            }
            $this->context->prove($depth);
        }

        return $result;
    }

    private function pinNativeTables(): void
    {
        $context = $this->context;
        $primary = $context->primary();
        $wait = (int) $primary->query('SELECT @@session.lock_wait_timeout')->fetchColumn();
        try {
            $primary->exec('SET SESSION lock_wait_timeout = '.min($wait, 5));
            // Callback-capable construction occurs during capture, never at the final commit fence.
            $configuration = $context->connection()->getConfig();
            $configuration['options'][PDO::ATTR_PERSISTENT] = false;
            $observer = app('db.factory')->make($configuration)->getPdo();
            $context->proveHeld();
            CheckoutException::require(in_array($observer::class, [PDO::class, Mysql::class], true)
                && (new ReflectionClass($observer))->isInternal()
                && $observer->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                && $observer->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
                && $observer->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
                && ! $observer->inTransaction()
                && (int) $observer->query('SELECT @@session.autocommit')->fetchColumn() === 1
                && $observer->query('SELECT DATABASE(), @@server_uuid')->fetch(PDO::FETCH_NUM)
                    === $primary->query('SELECT DATABASE(), @@server_uuid')->fetch(PDO::FETCH_NUM)
                && $observer->query('SELECT CONNECTION_ID()')->fetchColumn() !== $primary->query('SELECT CONNECTION_ID()')->fetchColumn(), 'committed_read_frame');
            $observer->exec('SET SESSION lock_wait_timeout = 5');
            foreach (TaxCheckoutSchema::TABLES as $table) {
                $primary->query('SELECT 1 FROM '.$this->qualified($table).' LIMIT 0')->fetchAll();
            }
            $this->definitions(1); // Primary SHOW CREATE rejects temporary shadows after physical pins.
            $tables = array_values(TaxCheckoutSchema::TABLES);
            $statement = $observer->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES'
                .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.implode(', ', array_fill(0, count($tables), '?')).') ORDER BY TABLE_NAME');
            $statement->execute([$context->database(), ...$tables]);
            $actual = $statement->fetchAll(PDO::FETCH_ASSOC);
            sort($tables, SORT_STRING);
            CheckoutException::require(array_column($actual, 'TABLE_NAME') === $tables, 'committed_read_frame');
            foreach ($actual as $table) {
                CheckoutException::require($table['TABLE_TYPE'] === 'BASE TABLE' && $table['ENGINE'] === 'InnoDB', 'committed_read_frame');
            }
        } catch (PDOException $error) {
            throw new CheckoutException(in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true) ? 'contention' : 'committed_read_frame', 503);
        } finally {
            $primary->exec('SET SESSION lock_wait_timeout = '.$wait);
        }
        $context->proveHeld();
    }

    private function qualified(string $table): string
    {
        return $this->context->driver() === 'mysql'
            ? '`'.str_replace('`', '``', $this->context->database()).'`.`'.$table.'`' : 'main.'.$table;
    }

    public function __serialize(): never
    {
        throw new LogicException('Tax raw reads cannot be serialized.');
    }

    private function __clone() {}

    public function jsonSerialize(): never
    {
        throw new LogicException('Tax raw readers are internal server objects.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax raw reads cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'fixed_tax_raw_reads'];
    }
}
