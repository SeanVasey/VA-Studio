<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Resume only an exact, contiguous, empty installation prefix. Never erase, replace or adopt drifted
 * objects, foreign identities, temporary shadows or foreign references. Installation requires stopped writers.
 */
final class TaxCheckoutSchemaInstaller
{
    public function up(): void
    {
        $driver = $this->driver();
        $statements = TaxCheckoutSchema::statements($driver);
        $present = $this->preflight($driver, $statements);
        $missing = false;
        foreach ($present as $exists) {
            if (! $exists) {
                $missing = true;
            } elseif ($missing) {
                $this->reject('installation gap');
            }
        }
        if ($missing) {
            foreach (TaxCheckoutSchema::TABLES as $table) {
                if (($present[$table] ?? false) && DB::table($table)->exists()) {
                    $this->reject('populated incomplete installation');
                }
            }
        }
        foreach ($statements as $name => $definition) {
            if (! $present[$name]) {
                DB::unprepared($definition['statement']);
            }
        }
        if (in_array(false, $this->preflight($driver, $statements), true)) {
            $this->reject('incomplete final installation');
        }
    }

    /** Read-only admission for runtime callers: never resume or install. */
    public function assertComplete(): void
    {
        $driver = $this->driver();
        if (in_array(false, $this->preflight($driver, TaxCheckoutSchema::statements($driver)), true)) {
            $this->reject('incomplete installation');
        }
    }

    public function down(): void
    {
        throw new LogicException('Retain production tax checkout orders, requests, provider bindings and buyer-reviewed sessions.');
    }

    private function driver(): string
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true) || $connection->getTablePrefix() !== '') {
            $this->reject('driver');
        }

        return $driver;
    }

    private function preflight(string $driver, array $statements): array
    {
        $present = array_fill_keys(array_keys($statements), false);
        $tables = TaxCheckoutSchema::definitions();
        if ($driver === 'sqlite') {
            $reserved = array_map('strtolower', TaxCheckoutSchema::reservedNames('sqlite'));
            foreach (DB::select('SELECT * FROM sqlite_temp_master') as $raw) {
                $object = (array) $raw;
                if (in_array(strtolower($object['name']), $reserved, true) || isset($tables[strtolower($object['tbl_name'])])) {
                    $this->reject('temporary shadow');
                }
            }
            foreach (DB::select('SELECT * FROM sqlite_master') as $raw) {
                $object = (array) $raw;
                $name = strtolower($object['name']);
                if (isset($statements[$name])) {
                    if ($present[$name] || $object['name'] !== $name || $object['type'] !== $statements[$name]['type']
                        || $object['tbl_name'] !== $statements[$name]['table'] || $object['sql'] !== $statements[$name]['statement']) {
                        $this->reject('object identity or definition');
                    }
                    $present[$name] = true;
                } elseif (in_array($name, $reserved, true) || isset($tables[strtolower($object['tbl_name'])])) {
                    $this->reject('additional owned object');
                } elseif (is_string($object['sql']) && $this->referencesOwned($object['sql'])) {
                    $this->reject('foreign reference');
                }
            }

            return $present;
        }

        $database = DB::getDatabaseName();
        $names = TaxCheckoutSchema::reservedNames('mysql');
        // Dictionary identities compare case/accent-insensitively; an alias of a reserved name is a collision.
        $reserved = implode(' UNION ALL ', array_fill(0, count($names), 'SELECT CONVERT(? USING utf8mb3) COLLATE utf8mb3_general_ci AS name'));
        foreach (['TABLES' => 'TABLE_NAME', 'TRIGGERS' => 'TRIGGER_NAME'] as $catalog => $column) {
            $schemaColumn = $catalog === 'TABLES' ? 'TABLE_SCHEMA' : 'TRIGGER_SCHEMA';
            $rows = DB::select('SELECT o.* FROM information_schema.'.$catalog.' o JOIN ('.$reserved.') r ON CONVERT(o.'.$column.' USING utf8mb3) COLLATE utf8mb3_general_ci = r.name WHERE o.'.$schemaColumn.' = ?', [...$names, $database]);
            foreach ($rows as $raw) {
                $object = (array) $raw;
                $name = $object[$column];
                $expectedType = $catalog === 'TABLES' ? 'table' : 'trigger';
                if (! isset($statements[$name]) || $statements[$name]['type'] !== $expectedType || $present[$name]) {
                    $this->reject('dictionary identity alias or foreign namespace');
                }
                if ($expectedType === 'table') {
                    $this->mysqlTable($name, $tables[$name], $object);
                } else {
                    $expected = $statements[$name];
                    if ($object['EVENT_OBJECT_TABLE'] !== $expected['table'] || $object['ACTION_TIMING'] !== 'BEFORE'
                        || $object['EVENT_MANIPULATION'] !== $expected['operation'] || $object['ACTION_ORIENTATION'] !== 'ROW'
                        || $object['ACTION_STATEMENT'] !== $expected['body'] || (int) $object['ACTION_ORDER'] !== 1) {
                        $this->reject('guard definition');
                    }
                }
                $present[$name] = true;
            }
        }
        foreach (DB::select('SELECT o.TABLE_NAME, o.CONSTRAINT_NAME, o.CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS o JOIN ('.$reserved.') r ON CONVERT(o.CONSTRAINT_NAME USING utf8mb3) COLLATE utf8mb3_general_ci = r.name WHERE o.CONSTRAINT_SCHEMA = ?', [...$names, $database]) as $constraint) {
            $definition = $tables[$constraint->TABLE_NAME] ?? null;
            $owned = $definition !== null && (($constraint->CONSTRAINT_TYPE === 'UNIQUE' && isset($definition['unique'][$constraint->CONSTRAINT_NAME]))
                || ($constraint->CONSTRAINT_TYPE === 'FOREIGN KEY' && in_array($constraint->CONSTRAINT_NAME,
                    array_map(fn (string $field): string => $definition['prefix'].'_f_'.$field, array_keys($definition['foreign'])), true)));
            if (! $owned) {
                $this->reject('foreign constraint dictionary identity');
            }
        }
        foreach ($tables as $name => $definition) {
            try {
                $shown = (array) DB::selectOne('SHOW CREATE TABLE '.$name);
                if (str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE ') || (! $present[$name] && isset($shown['Create Table']))) {
                    $this->reject('temporary shadow');
                }
            } catch (QueryException $error) {
                if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
            foreach (DB::select('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?', [$database, $name]) as $guard) {
                if (! isset($statements[$guard->TRIGGER_NAME])) {
                    $this->reject('additional owned guard');
                }
            }
        }
        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        foreach (DB::select('SELECT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IN ('.$placeholders.')', [$database, ...array_keys($tables)]) as $key) {
            if (! isset($tables[$key->TABLE_NAME])) {
                $this->reject('foreign key consumer');
            }
        }
        foreach (DB::select('SELECT TABLE_NAME, VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = ?', [$database]) as $view) {
            if ($view->VIEW_DEFINITION === null || $this->referencesOwned($view->VIEW_DEFINITION)) {
                $this->reject('foreign view');
            }
        }
        foreach (DB::select('SELECT TRIGGER_NAME, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ?', [$database]) as $guard) {
            if (! isset($statements[$guard->TRIGGER_NAME]) && $this->referencesOwned($guard->ACTION_STATEMENT)) {
                $this->reject('foreign guard reference');
            }
        }
        foreach (DB::select('SELECT ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = ?', [$database]) as $routine) {
            if ($routine->ROUTINE_DEFINITION === null || $this->referencesOwned($routine->ROUTINE_DEFINITION)) {
                $this->reject('foreign or opaque routine reference');
            }
        }

        return $present;
    }

    private function mysqlTable(string $table, array $definition, array $object): void
    {
        if ($object['TABLE_NAME'] !== $table || $object['TABLE_TYPE'] !== 'BASE TABLE' || $object['ENGINE'] !== 'InnoDB'
            || $object['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci' || $object['CREATE_OPTIONS'] !== '' || $object['TABLE_COMMENT'] !== '') {
            $this->reject('table metadata');
        }
        $database = DB::getDatabaseName();
        $columns = DB::select('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$database, $table]);
        $expected = [];
        foreach ($definition['columns'] as $name => $type) {
            $integer = in_array($type, ['id', 'bigint', 'integer'], true);
            $expected[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type === 'id' || $type === 'bigint' ? 'bigint unsigned' : ($type === 'integer' ? 'int unsigned' : $type),
                'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => $type === 'id' ? 'auto_increment' : '',
                'CHARACTER_SET_NAME' => $integer ? null : 'ascii', 'COLLATION_NAME' => $integer ? null : 'ascii_bin', 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        }
        $this->same($expected, array_map(fn ($row): array => (array) $row, $columns), 'columns');
        $indexes = ['PRIMARY' => [0, ['id']]];
        foreach ($definition['unique'] as $name => $fields) {
            $indexes[$name] = [0, $fields];
        }
        foreach ($definition['foreign'] as $field => $parent) {
            $indexes[$definition['prefix'].'_f_'.$field] = [1, [$field]];
        }
        $actual = [];
        foreach (DB::select('SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE, SUB_PART, COLLATION, EXPRESSION, IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$database, $table]) as $index) {
            if ($index->INDEX_TYPE !== 'BTREE' || $index->SUB_PART !== null || $index->COLLATION !== 'A' || $index->EXPRESSION !== null || $index->IS_VISIBLE !== 'YES') {
                $this->reject('index options');
            }
            $actual[$index->INDEX_NAME][0] = (int) $index->NON_UNIQUE;
            $actual[$index->INDEX_NAME][1][] = $index->COLUMN_NAME;
            if ((int) $index->SEQ_IN_INDEX !== count($actual[$index->INDEX_NAME][1])) {
                $this->reject('index ordering');
            }
        }
        ksort($indexes);
        ksort($actual);
        $this->same($indexes, $actual, 'indexes');
        $foreign = [];
        foreach ($definition['foreign'] as $field => $parent) {
            $foreign[$definition['prefix'].'_f_'.$field] = [$field, $database, $parent, 'id', 'RESTRICT', 'RESTRICT'];
        }
        $actual = [];
        foreach (DB::select('SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.TABLE_NAME = k.TABLE_NAME AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ?', [$database, $table]) as $key) {
            if (isset($actual[$key->CONSTRAINT_NAME])) {
                $this->reject('compound foreign key');
            }
            $actual[$key->CONSTRAINT_NAME] = [$key->COLUMN_NAME, $key->REFERENCED_TABLE_SCHEMA, $key->REFERENCED_TABLE_NAME, $key->REFERENCED_COLUMN_NAME, $key->UPDATE_RULE, $key->DELETE_RULE];
        }
        ksort($foreign);
        ksort($actual);
        $this->same($foreign, $actual, 'foreign keys');
        $constraints = DB::select('SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]);
        if (count($constraints) !== 1 + count($definition['unique']) + count($foreign)) {
            $this->reject('additional constraint');
        }
    }

    /** A foreign object naming an owned table could fire, block or read through it; refuse before DDL. */
    private function referencesOwned(string $sql): bool
    {
        foreach (TaxCheckoutSchema::TABLES as $table) {
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    private function same(array $expected, array $actual, string $what): void
    {
        if ($expected !== $actual) {
            $this->reject($what);
        }
    }

    private function reject(string $reason): never
    {
        throw new LogicException('Production tax checkout migration refused before further DDL: '.$reason.'.');
    }

    /** @internal Test seam documenting the V1 boundary: no owned statement names a V1 checkout table. */
    public static function referencesV1(string $driver): bool
    {
        foreach (TaxCheckoutSchema::statements($driver) as $definition) {
            foreach (CheckoutSchema::TABLES as $table) {
                if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i', $definition['statement'])) {
                    return true;
                }
            }
        }

        return false;
    }
}
