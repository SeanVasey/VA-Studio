<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandFrame;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use LogicException;
use PDO;

/** Fixed Tax255/historical-ownership comparisons; expected arrays confer no authority themselves. */
final class TaxCheckoutRawPlans
{
    private array $plans = [];

    private array $definitions = [];

    public function __construct(private readonly CheckoutCommandFrame $frame) {}

    /** @internal Only the authenticated admission factory chooses fixed selectors and projections. */
    public function plan(string $table, string $where, array $bindings, int $limit, array $expected, array $columns = ['*']): void
    {
        CheckoutException::require(in_array($table, [...array_values(TaxCheckoutSchema::TABLES), 'users', 'customer_accounts',
            'production_identity_origins', 'production_identity_verifications', 'production_identity_challenges'], true)
            && $limit >= 1 && $limit <= 129, 'write_frame');
        foreach ($columns as $column) {
            CheckoutException::require($column === '*' || preg_match('/\A[a-z][a-z0-9_]*\z/D', $column) === 1, 'write_frame');
        }
        $definition = $this->definition($table);
        $raw = $this->read($table, $where, $bindings, $limit, $columns);
        CheckoutException::require(self::strings($raw) === self::strings($expected), 'write_source_changed');
        $this->definitions[$table] = $definition;
        $this->plans[] = compact('table', 'where', 'bindings', 'limit', 'columns', 'raw');
    }

    /** Callback-free captured primary, permanent definitions and raw rows only. */
    public function proveCurrent(): void
    {
        $this->frame->prove(1);
        foreach ($this->definitions as $table => $definition) {
            CheckoutException::require($this->definition($table) === $definition, 'write_source_changed');
        }
        foreach ($this->plans as $plan) {
            CheckoutException::require($this->read($plan['table'], $plan['where'], $plan['bindings'], $plan['limit'], $plan['columns']) === $plan['raw'], 'write_source_changed');
        }
        $this->frame->prove(1);
    }

    private function read(string $table, string $where, array $bindings, int $limit, array $columns): array
    {
        $this->frame->prove(1);
        $statement = $this->frame->primary()->prepare('SELECT '.implode(', ', $columns).' FROM '.$this->qualified($table)
            .' WHERE '.$where.' ORDER BY id LIMIT '.$limit);
        $statement->execute($bindings);
        $this->frame->provePdo();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->frame->prove(1);

        return $rows;
    }

    private function definition(string $table): array
    {
        $this->frame->prove(1);
        $primary = $this->frame->primary();
        if ($this->frame->driver() === 'mysql') {
            $row = $primary->query('SHOW CREATE TABLE '.$this->qualified($table))->fetch(PDO::FETCH_NUM);
            CheckoutException::require(is_array($row) && is_string($row[1] ?? null) && str_starts_with($row[1], 'CREATE TABLE '), 'write_source_changed');
            // The allocator counter is not table structure or selected-row identity.
            $row[1] = preg_replace('/(\n\) ENGINE=InnoDB) AUTO_INCREMENT=[0-9]+(?= DEFAULT CHARSET=)/', '$1', $row[1]);
            $definition = $row;
        } else {
            $statement = $primary->prepare('SELECT name, type, sql FROM main.sqlite_schema WHERE name = ? COLLATE NOCASE');
            $statement->execute([$table]);
            $definition = $statement->fetchAll(PDO::FETCH_ASSOC);
            CheckoutException::require(count($definition) === 1 && $definition[0]['type'] === 'table', 'temporary_shadow');
            $temporary = $primary->prepare('SELECT name, tbl_name FROM temp.sqlite_schema WHERE name = ? COLLATE NOCASE OR tbl_name = ? COLLATE NOCASE');
            $temporary->execute([$table, $table]);
            CheckoutException::require($temporary->fetchAll(PDO::FETCH_ASSOC) === [], 'temporary_shadow');
        }
        $this->frame->prove(1);

        return $definition;
    }

    private function qualified(string $table): string
    {
        return $this->frame->driver() === 'mysql'
            ? '`'.str_replace('`', '``', $this->frame->database()).'`.`'.$table.'`'
            : 'main.'.$table;
    }

    private static function strings(array $values): array
    {
        foreach ($values as &$value) {
            $value = is_array($value) ? self::strings($value) : ($value === null ? null : (string) $value);
        }
        ksort($values);

        return $values;
    }

    public function __serialize(): never
    {
        throw new LogicException('Tax checkout raw plans are an internal server capability.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax checkout raw plans cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_tax_checkout_raw_plans'];
    }
}
