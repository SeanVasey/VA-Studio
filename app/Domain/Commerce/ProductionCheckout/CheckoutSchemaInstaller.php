<?php

namespace App\Domain\Commerce\ProductionCheckout;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Resume an exact, empty installation prefix. Never erase or adopt drifted records or foreign identities. */
final class CheckoutSchemaInstaller
{
    public function up(): void
    {
        $statements = CheckoutSchema::statements();
        $present = $this->preflight($statements);
        $missing = false;
        foreach ($present as $exists) {
            if (! $exists) {
                $missing = true;
            } elseif ($missing) {
                $this->reject('installation gap');
            }
        }
        if ($missing) {
            foreach (CheckoutSchema::TABLES as $table) {
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
        if (in_array(false, $this->preflight($statements), true)) {
            $this->reject('incomplete final installation');
        }
    }

    private function preflight(array $statements): array
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            $this->reject('driver');
        }
        $present = array_fill_keys(array_keys($statements), false);
        $tables = CheckoutSchema::definitions();
        if ($driver === 'sqlite') {
            $reserved = array_keys($statements);
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
                } elseif (isset($tables[strtolower($object['tbl_name'])])) {
                    $this->reject('additional owned object');
                } elseif (is_string($object['sql']) && $this->referencesOwned($object['sql'])) {
                    $this->reject('foreign reference');
                }
            }

            return $present;
        }

        $database = DB::getDatabaseName();
        $names = [...array_keys($statements)];
        foreach ($tables as $definition) {
            $names = [...$names, ...array_keys($definition['unique'])];
            foreach ($definition['foreign'] as $field => $parent) {
                $names[] = $definition['prefix'].'_f_'.$field;
            }
        }
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
                    $identity = DB::selectOne('SELECT CURRENT_USER() AS definer, @@SESSION.sql_mode AS sql_mode');
                    if ($object['EVENT_OBJECT_TABLE'] !== $expected['table'] || $object['ACTION_TIMING'] !== 'BEFORE'
                        || $object['EVENT_MANIPULATION'] !== $expected['operation'] || $object['ACTION_ORIENTATION'] !== 'ROW'
                        || $object['ACTION_STATEMENT'] !== $expected['body'] || $object['DEFINER'] !== $identity->definer
                        || $object['SQL_MODE'] !== $identity->sql_mode || (int) $object['ACTION_ORDER'] !== 1) {
                        $this->reject('guard definition');
                    }
                }
                $present[$name] = true;
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
        Evidence::same($expected, array_map(fn ($r): array => (array) $r, $columns));
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
        Evidence::same($indexes, $actual);
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
        Evidence::same($foreign, $actual);
        $constraints = DB::select('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, $table]);
        if (count($constraints) !== 1 + count($definition['unique']) + count($foreign)) {
            $this->reject('additional constraint');
        }
    }

    private function referencesOwned(string $sql): bool
    {
        foreach (CheckoutSchema::TABLES as $table) {
            if (preg_match('/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    private function reject(string $reason): never
    {
        throw new LogicException('Production checkout migration refused before further DDL: '.$reason.'.');
    }
}
