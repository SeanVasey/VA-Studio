<?php

namespace App\Domain\Customers\ProductionIdentity;

use LogicException;
use PDO;
use PDOException;

/** Exact empty DDL-prefix recovery; ownership admission performs no application/query callbacks. */
final class IdentityMigrationOwnership
{
    public function inspect(PDO $pdo, string $driver): array
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            $this->reject();
        }
        $definitions = IdentitySchema::definitions();
        $guards = IdentitySchema::guards($driver);
        $tables = array_keys($definitions);
        $parents = ['users', 'customer_accounts', 'quote_owners'];
        $reserved = [];
        foreach ($definitions as $definition) {
            $reserved = [...$reserved, ...array_keys($definition['unique']), ...array_keys($definition['foreign'])];
        }
        $present = [];
        if ($driver === 'sqlite') {
            $objects = $pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $temporary = $pdo->query('SELECT type,name,tbl_name,sql FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($temporary as $object) {
                if (in_array(strtolower($object['name']), [...$tables, ...array_keys($guards), ...$parents, ...$reserved], true)
                    || in_array(strtolower($object['tbl_name']), $tables, true)) {
                    $this->reject();
                }
            }
            foreach ($objects as $object) {
                if (in_array(strtolower($object['name']), $reserved, true)) {
                    $this->reject();
                }
            }
            foreach ($parents as $parent) {
                $matches = array_values(array_filter($objects, fn ($object) => strtolower($object['name']) === $parent));
                if (count($matches) !== 1 || $matches[0]['name'] !== $parent || $matches[0]['type'] !== 'table') {
                    $this->reject();
                }
            }
            foreach ($tables as $table) {
                $matches = array_values(array_filter($objects, fn ($object) => strtolower($object['name']) === $table));
                if (count($matches) > 1 || ($matches !== [] && ($matches[0]['name'] !== $table || $matches[0]['type'] !== 'table'
                    || $matches[0]['sql'] !== IdentitySchema::tableSql($table, $driver)))) {
                    $this->reject();
                }
                $present[$table] = $matches !== [];
                if ($present[$table]) {
                    $indexes = $pdo->query('PRAGMA main.index_list(`'.$table.'`)')->fetchAll(PDO::FETCH_ASSOC);
                    $actual = [];
                    foreach ($indexes as $index) {
                        if ((int) $index['unique'] !== 1 || $index['origin'] !== 'u' || (int) $index['partial'] !== 0) {
                            $this->reject();
                        }
                        $parts = $pdo->query('PRAGMA main.index_info(`'.$index['name'].'`)')->fetchAll(PDO::FETCH_ASSOC);
                        $actual[] = array_column($parts, 'name');
                    }
                    $expected = array_values($definitions[$table]['unique']);
                    sort($actual);
                    sort($expected);
                    if ($actual !== $expected) {
                        $this->reject();
                    }
                }
                foreach ($objects as $object) {
                    if (strtolower($object['tbl_name']) === $table && $object['name'] !== $table
                        && ! isset($guards[$object['name']]) && ! ($object['type'] === 'index' && $object['sql'] === null
                            && str_starts_with($object['name'], 'sqlite_autoindex_'.$table.'_'))) {
                        $this->reject();
                    }
                }
            }
            foreach ($guards as $name => $guard) {
                $matches = array_values(array_filter($objects, fn ($object) => strtolower($object['name']) === $name));
                if (count($matches) > 1 || ($matches !== [] && (! $present[$guard['table']] || $matches[0]['name'] !== $name
                    || $matches[0]['type'] !== 'trigger' || $matches[0]['tbl_name'] !== $guard['table'] || $matches[0]['sql'] !== $guard['sql']))) {
                    $this->reject();
                }
                $present[$name] = $matches !== [];
            }
            foreach (['main' => $objects, 'temp' => $temporary] as $schema => $catalog) {
                foreach ($catalog as $object) {
                    if ($schema === 'main' && (in_array($object['name'], $tables, true) || isset($guards[$object['name']]))) {
                        continue;
                    }
                    if ($object['type'] === 'table') {
                        $name = str_replace('"', '""', $object['name']);
                        foreach ($pdo->query('PRAGMA '.$schema.'.foreign_key_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC) as $key) {
                            if (in_array(strtolower($key['table']), $tables, true)) {
                                $this->reject();
                            }
                        }
                    } elseif (in_array($object['type'], ['view', 'trigger'], true) && $this->references($object['sql'], $tables)) {
                        $this->reject();
                    }
                }
            }
        } else {
            foreach ([...$parents, ...$tables] as $table) {
                $rows = $this->query($pdo, 'SELECT * FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=?', [$table]);
                if (count($rows) > 1 || ($rows !== [] && ($rows[0]['TABLE_NAME'] !== $table || $rows[0]['TABLE_TYPE'] !== 'BASE TABLE'
                    || $rows[0]['ENGINE'] !== 'InnoDB' || $rows[0]['CREATE_OPTIONS'] !== '' || $rows[0]['TABLE_COMMENT'] !== ''))) {
                    $this->reject();
                }
                if (in_array($table, $parents, true) && $rows === []) {
                    $this->reject();
                }
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_ASSOC);
                    if ($rows === [] || str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE ')) {
                        $this->reject();
                    }
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1146 || $rows !== []) {
                        throw $exception;
                    }
                }
                if (in_array($table, $tables, true)) {
                    $present[$table] = $rows !== [];
                    if ($rows !== []) {
                        $this->mysqlTable($pdo, $table, $definitions[$table], $rows[0]);
                    }
                }
            }
            foreach ([...array_keys($guards), ...$reserved] as $reservedName) {
                if ($this->query($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=?', [$reservedName]) !== []) {
                    $this->reject();
                }
                try {
                    $pdo->query('SHOW CREATE TABLE `'.$reservedName.'`');
                    $this->reject();
                } catch (PDOException $exception) {
                    if ((int) ($exception->errorInfo[1] ?? 0) !== 1146) {
                        throw $exception;
                    }
                }
                if (in_array($reservedName, $reserved, true) && $this->query($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND LOWER(TRIGGER_NAME)=?', [$reservedName]) !== []) {
                    $this->reject();
                }
            }
            foreach ($guards as $name => $guard) {
                $rows = $this->query($pdo, 'SELECT * FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND LOWER(TRIGGER_NAME)=?', [$name]);
                if (count($rows) > 1 || ($rows !== [] && (! $present[$guard['table']] || $rows[0]['TRIGGER_NAME'] !== $name
                    || $rows[0]['EVENT_OBJECT_TABLE'] !== $guard['table'] || $rows[0]['EVENT_MANIPULATION'] !== $guard['operation']
                    || $rows[0]['ACTION_TIMING'] !== 'BEFORE' || $rows[0]['ACTION_STATEMENT'] !== $guard['body']))) {
                    $this->reject();
                }
                if ($this->query($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)=?', [$name]) !== []) {
                    $this->reject();
                }
                $present[$name] = $rows !== [];
            }
            foreach ($tables as $table) {
                if ($this->query($pdo, 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND LOWER(TRIGGER_NAME)=?', [$table]) !== []) {
                    $this->reject();
                }
            }
            foreach ($definitions as $table => $definition) {
                foreach ([...array_keys($definition['unique']), ...array_keys($definition['foreign'])] as $name) {
                    foreach ($this->query($pdo, 'SELECT TABLE_NAME,INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND LOWER(INDEX_NAME)=?', [$name]) as $index) {
                        if ($index['TABLE_NAME'] !== $table || $index['INDEX_NAME'] !== $name || ! $present[$table]) {
                            $this->reject();
                        }
                    }
                    foreach ($this->query($pdo, 'SELECT TABLE_NAME,CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND LOWER(CONSTRAINT_NAME)=?', [$name]) as $constraint) {
                        if ($constraint['TABLE_NAME'] !== $table || $constraint['CONSTRAINT_NAME'] !== $name || ! $present[$table]) {
                            $this->reject();
                        }
                    }
                }
            }
            foreach ($pdo->query('SELECT * FROM information_schema.TRIGGERS')->fetchAll(PDO::FETCH_ASSOC) as $object) {
                if (isset($guards[$object['TRIGGER_NAME']]) && $object['TRIGGER_SCHEMA'] === $this->database($pdo)) {
                    continue;
                }
                if (($object['TRIGGER_SCHEMA'] === $this->database($pdo) && in_array($object['EVENT_OBJECT_TABLE'], $tables, true))
                    || $this->references($object['ACTION_STATEMENT'], $tables)) {
                    $this->reject();
                }
            }
            foreach ($pdo->query('SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE REFERENCED_TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $key) {
                if (in_array(strtolower((string) $key['REFERENCED_TABLE_NAME']), $tables, true)
                    && ($key['TABLE_SCHEMA'] !== $this->database($pdo) || ! in_array($key['TABLE_NAME'], $tables, true))) {
                    $this->reject();
                }
            }
            foreach (['VIEWS' => 'VIEW_DEFINITION', 'ROUTINES' => 'ROUTINE_DEFINITION'] as $catalog => $column) {
                foreach ($pdo->query('SELECT '.$column.' FROM information_schema.'.$catalog)->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    if ($this->references($object[$column], $tables)) {
                        $this->reject();
                    }
                }
            }
        }
        $missing = false;
        foreach ($present as $installed) {
            if ($installed && $missing) {
                $this->reject();
            }
            $missing = $missing || ! $installed;
        }
        $populated = false;
        foreach ($tables as $table) {
            if ($present[$table] && $pdo->query('SELECT id FROM `'.$table.'` LIMIT 1')->fetchColumn() !== false) {
                $populated = true;
            }
        }
        if ($missing && $populated) {
            $this->reject();
        }

        return [$present, $populated];
    }

    private function mysqlTable(PDO $pdo, string $table, array $definition, array $tableRow): void
    {
        if ($tableRow['TABLE_COLLATION'] !== 'utf8mb4_unicode_ci') {
            $this->reject();
        }
        $expected = ['id' => 'bigint'] + $definition['columns'];
        $columns = $this->query($pdo, 'SELECT * FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]);
        if (array_column($columns, 'COLUMN_NAME') !== array_keys($expected)) {
            $this->reject();
        }
        foreach ($columns as $row) {
            $name = $row['COLUMN_NAME'];
            $type = preg_replace('/ CHARACTER SET.*$/', '', IdentitySchema::columnSql($expected[$name], 'mysql'));
            $ascii = in_array($expected[$name], ['uuid', 'hash', 'version', 'scope', 'state', 'purpose'], true);
            $charset = $ascii ? 'ascii' : ($expected[$name] === 'text' ? 'utf8mb4' : null);
            $collation = $ascii ? 'ascii_bin' : ($expected[$name] === 'text' ? 'utf8mb4_unicode_ci' : null);
            if ($row['COLUMN_TYPE'] !== $type || $row['IS_NULLABLE'] !== 'NO' || $row['COLUMN_DEFAULT'] !== null
                || $row['COLUMN_COMMENT'] !== '' || $row['GENERATION_EXPRESSION'] !== ''
                || $row['EXTRA'] !== ($name === 'id' ? 'auto_increment' : '')
                || $row['CHARACTER_SET_NAME'] !== $charset || $row['COLLATION_NAME'] !== $collation) {
                $this->reject();
            }
        }
        $indexes = ['PRIMARY' => [['id'], 0]];
        foreach ($definition['unique'] as $name => $fields) {
            $indexes[$name] = [$fields, 0];
        }
        foreach ($definition['foreign'] as $name => [$column]) {
            if (! in_array([$column], $definition['unique'], true)) {
                $indexes[$name] = [[$column], 1];
            }
        }
        $parts = $this->query($pdo, 'SELECT * FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]);
        $actual = [];
        foreach ($parts as $part) {
            if (! isset($indexes[$part['INDEX_NAME']]) || $part['SUB_PART'] !== null || $part['EXPRESSION'] !== null
                || $part['INDEX_TYPE'] !== 'BTREE' || $part['COLLATION'] !== 'A' || $part['IS_VISIBLE'] !== 'YES' || $part['INDEX_COMMENT'] !== '') {
                $this->reject();
            }
            $actual[$part['INDEX_NAME']][0][] = $part['COLUMN_NAME'];
            $actual[$part['INDEX_NAME']][1] = (int) $part['NON_UNIQUE'];
        }
        ksort($indexes);
        ksort($actual);
        if ($actual !== $indexes) {
            $this->reject();
        }
        $keys = $this->query($pdo, 'SELECT k.*,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=?', [$table]);
        if (count($keys) !== count($definition['foreign'])) {
            $this->reject();
        }
        foreach ($keys as $key) {
            $expectedKey = $definition['foreign'][$key['CONSTRAINT_NAME']] ?? null;
            if ($expectedKey !== [$key['COLUMN_NAME'], $key['REFERENCED_TABLE_NAME']] || $key['REFERENCED_COLUMN_NAME'] !== 'id'
                || $key['REFERENCED_TABLE_SCHEMA'] !== $this->database($pdo) || (int) $key['ORDINAL_POSITION'] !== 1
                || $key['DELETE_RULE'] !== 'RESTRICT' || $key['UPDATE_RULE'] !== 'NO ACTION') {
                $this->reject();
            }
        }
        if ($this->query($pdo, "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_TYPE='CHECK'", [$table]) !== []) {
            $this->reject();
        }
    }

    private function references(mixed $sql, array $tables): bool
    {
        if (! is_string($sql)) {
            $this->reject();
        }
        foreach ($tables as $table) {
            if (preg_match('/(?<![a-z0-9_])'.preg_quote($table, '/').'(?![a-z0-9_])/i', $sql)) {
                return true;
            }
        }

        return false;
    }

    private function query(PDO $pdo, string $sql, array $bindings): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function database(PDO $pdo): string
    {
        return $pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    private function reject(): never
    {
        throw new LogicException('Unexpected production identity schema or retained evidence; refused before DDL.');
    }
}
