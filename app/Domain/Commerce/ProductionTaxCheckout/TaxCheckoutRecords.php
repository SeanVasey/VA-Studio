<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandFrame;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use ReflectionClass;
use Throwable;

/**
 * Fixed owned-table access on the captured primary PDO: bound values, no query listeners or model hooks.
 * Writers exist only inside a V1 command frame (`CommandTransaction::run`); readers reuse a held CurrentRows.
 */
final readonly class TaxCheckoutRecords
{
    private function __construct(public CurrentRows $current, private bool $writer, private ?CheckoutCommandFrame $frame = null) {}

    public static function writer(Records $rows): self
    {
        $frame = $rows->commandFrame();
        $records = new self($rows->current, true, $frame);
        $records->proveTables();

        return $records;
    }

    public static function reader(CurrentRows $reader): self
    {
        $records = new self($reader, false);
        $records->proveTables();

        return $records;
    }

    public function insert(string $kind, array $row): array
    {
        $table = TaxCheckoutSchema::TABLES[$kind] ?? null;
        $primary = $this->current->identityPrimary();
        CheckoutException::require($this->writer && $table !== null && $primary->inTransaction(), 'write_frame');
        $this->pinTransactionalStorage();
        $columns = TaxCheckoutSchema::definitions()[$table]['columns'];
        unset($columns['id']);
        Evidence::keys($row, array_keys($columns));
        $statement = $primary->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
        $id = (int) $primary->lastInsertId();
        $actual = $this->current->one($table, $id);
        Evidence::same(['id' => $id, ...$row], $actual);

        return $actual;
    }

    public function one(string $kind, string $publicId): array
    {
        $rows = $this->selector($kind, 'public_id = ?', [$publicId]);
        CheckoutException::require(count($rows) === 1, 'not_found', 404);

        return $rows[0];
    }

    public function byId(string $kind, int $id): array
    {
        $rows = $this->selector($kind, 'id = ?', [$id]);
        CheckoutException::require(count($rows) === 1);

        return $rows[0];
    }

    public function selector(string $kind, string $where, array $bindings, int $limit = 2): array
    {
        CheckoutException::require(isset(TaxCheckoutSchema::TABLES[$kind]));

        return $this->current->rows(TaxCheckoutSchema::TABLES[$kind], $where, $bindings, $limit);
    }

    /** Owned tables must be the permanent schema objects, never a connection-local temporary shadow. */
    public function proveTables(): void
    {
        $primary = $this->current->identityPrimary();
        $tables = array_values(TaxCheckoutSchema::TABLES);
        try {
            if ($this->current->identityDriver() === 'sqlite') {
                foreach ($primary->query('SELECT name, tbl_name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    CheckoutException::require(! in_array(strtolower($object['name']), $tables, true) && ! in_array(strtolower($object['tbl_name']), $tables, true), 'temporary_shadow');
                }
                $statement = $primary->prepare("SELECT COUNT(*) FROM main.sqlite_master WHERE type = 'table' AND name IN (".implode(', ', array_fill(0, count($tables), '?')).')');
                $statement->execute($tables);
                CheckoutException::require((int) $statement->fetchColumn() === count($tables), 'permanent_schema');

                return;
            }
            $database = $primary->query('SELECT DATABASE()')->fetchColumn();
            CheckoutException::require(is_string($database), 'primary_schema');
            $schema = '`'.str_replace('`', '``', $database).'`';
            foreach ($tables as $table) {
                $definition = $primary->query('SHOW CREATE TABLE '.$schema.'.`'.$table.'`')->fetch(PDO::FETCH_NUM);
                CheckoutException::require(is_array($definition) && is_string($definition[1] ?? null) && str_starts_with($definition[1], 'CREATE TABLE '), 'temporary_or_foreign_schema');
            }
        } catch (CheckoutException $error) {
            throw $error;
        } catch (Throwable) {
            throw new CheckoutException('permanent_schema', 503);
        }
    }

    /** Before NEW DML: permanent table pins, then fresh committed structured engine metadata. */
    private function pinTransactionalStorage(): void
    {
        CheckoutException::require($this->frame !== null, 'write_frame');
        $frame = $this->frame;
        $frame->proveAnchor();
        if ($frame->driver() === 'sqlite') {
            $this->proveTables();
            CheckoutException::require(strtolower((string) $frame->primary()->query('PRAGMA journal_mode')->fetchColumn()) !== 'off', 'permanent_schema');
            $frame->proveAnchor();

            return;
        }
        CheckoutException::require($frame->driver() === 'mysql', 'permanent_schema');
        $primary = $frame->primary();
        $wait = (int) $primary->query('SELECT @@session.lock_wait_timeout')->fetchColumn();
        try {
            $primary->exec('SET SESSION lock_wait_timeout = '.min($wait, 5));
            $configuration = $frame->connection()->getConfig();
            $configuration['options'][PDO::ATTR_PERSISTENT] = false;
            $observer = app('db.factory')->make($configuration)->getPdo();
            $frame->proveAnchor();
            CheckoutException::require(in_array($observer::class, [PDO::class, Mysql::class], true)
                && (new ReflectionClass($observer))->isInternal()
                && $observer->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
                && $observer->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
                && $observer->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
                && ! $observer->inTransaction(), 'permanent_schema');
            CheckoutException::require((int) $observer->query('SELECT @@session.autocommit')->fetchColumn() === 1
                && $observer->query('SELECT DATABASE(), @@server_uuid')->fetch(PDO::FETCH_NUM)
                    === $primary->query('SELECT DATABASE(), @@server_uuid')->fetch(PDO::FETCH_NUM)
                && $observer->query('SELECT CONNECTION_ID()')->fetchColumn() !== $primary->query('SELECT CONNECTION_ID()')->fetchColumn(), 'primary_schema');
            $observer->exec('SET SESSION lock_wait_timeout = 5');
            $tables = array_values(TaxCheckoutSchema::TABLES);
            $schema = '`'.str_replace('`', '``', $frame->database()).'`';
            foreach ($tables as $table) {
                $primary->query('SELECT 1 FROM '.$schema.'.`'.$table.'` LIMIT 0')->fetchAll();
            }
            // Primary SHOW CREATE rejects temporary shadows; its transaction pins block peer engine changes.
            $this->proveTables();
            $statement = $observer->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES'
                .' WHERE TABLE_SCHEMA = ? AND TABLE_NAME IN ('.implode(', ', array_fill(0, count($tables), '?')).') ORDER BY TABLE_NAME');
            $statement->execute([$frame->database(), ...$tables]);
            $actual = $statement->fetchAll(PDO::FETCH_ASSOC);
            sort($tables, SORT_STRING);
            CheckoutException::require(array_column($actual, 'TABLE_NAME') === $tables, 'permanent_schema');
            foreach ($actual as $table) {
                CheckoutException::require($table['TABLE_TYPE'] === 'BASE TABLE' && $table['ENGINE'] === 'InnoDB', 'permanent_schema');
            }
            $frame->proveAnchor();
        } catch (PDOException $error) {
            throw new CheckoutException(in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true) ? 'contention' : 'permanent_schema', 503);
        } finally {
            $primary->exec('SET SESSION lock_wait_timeout = '.$wait);
        }
        $frame->proveAnchor();
    }
}
