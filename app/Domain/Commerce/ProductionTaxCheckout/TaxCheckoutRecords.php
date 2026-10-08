<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\Evidence;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use PDO;
use Throwable;

/**
 * Fixed owned-table access on the captured primary PDO: bound values, no query listeners or model hooks.
 * Writers exist only inside a V1 command frame (`CommandTransaction::run`); readers reuse a held CurrentRows.
 */
final readonly class TaxCheckoutRecords
{
    private function __construct(public CurrentRows $current, private bool $writer) {}

    public static function writer(Records $rows): self
    {
        $rows->commandFrame();
        $records = new self($rows->current, true);
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
}
