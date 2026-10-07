<?php

namespace App\Domain\Services\Projects;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;

/** Append only exact owned installation prefixes; never repair drift or discard evidence. */
final class ServiceProjectSchema
{
    public static function install(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $prefix = $connection->getTablePrefix();
        if (! in_array($driver, ['sqlite', 'mysql'], true) || ! preg_match('/\A[a-zA-Z0-9_]{0,16}\z/D', $prefix)) {
            throw new LogicException('Service projects require SQLite or MySQL and a bounded safe prefix.');
        }
        $connection->getSchemaBuilder();
        [$steps, $states, $names] = self::plan($driver, $prefix);
        $pdo = $connection->getPdo();
        $installed = self::preflight($pdo, $driver, $prefix, $states, $names);
        // Operative migration requires application writers and competing migrators stopped.
        // Every framework DDL is independently committed on MySQL and independently retryable.
        foreach (array_slice($steps, $installed) as $offset => $sql) {
            if (self::preflight($pdo, $driver, $prefix, $states, $names) !== $installed + $offset) {
                throw new LogicException('Service installation changed during recovery.');
            }
            DB::unprepared($sql);
        }
        if (self::preflight($pdo, $driver, $prefix, $states, $names) !== count($steps)) {
            throw new LogicException('Service installation did not finish exactly.');
        }
    }

    private static function plan(string $driver, string $prefix): array
    {
        $parent = $prefix.'service_projects';
        $events = $prefix.'service_project_events';
        $definitions = [];
        $definitions['service_projects'] = function (Blueprint $table) use ($parent): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('customer_account_id')->constrained('customer_accounts')->restrictOnDelete();
            $table->foreignId('service_version_id')->constrained('service_draft_versions')->restrictOnDelete();
            $table->longText('service_manifest');
            $table->char('service_hash', 64);
            $table->longText('brief');
            $table->char('brief_hash', 64);
            $table->uuid('creation_key');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at');
            $table->unique('public_id', $parent.'_public');
            $table->unique(['customer_account_id', 'creation_key'], $parent.'_request');
        };
        $definitions['service_project_events'] = function (Blueprint $table) use ($events): void {
            $table->engine = 'InnoDB';
            $table->id();
            $table->uuid('public_id');
            $table->foreignId('project_id')->constrained('service_projects')->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->string('operation', 40);
            $table->string('actor_kind', 8);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->char('request_hash', 64);
            $table->longText('payload');
            $table->char('payload_hash', 64);
            $table->timestamp('created_at');
            $table->unique('public_id', $events.'_public');
            $table->unique(['project_id', 'number'], $events.'_number');
            $table->unique(['project_id', 'request_key'], $events.'_request');
        };

        $guards = [];
        $wrap = DB::connection()->getQueryGrammar()->wrap(...);
        foreach ([$parent, $events] as $table) {
            $guards[] = self::guard($driver, $table.'_immutable', $wrap($table), 'UPDATE');
            $guards[] = self::guard($driver, $table.'_retain', $wrap($table), 'DELETE');
        }
        $hash = fn (string $column): string => $driver === 'mysql'
            ? '(CHAR_LENGTH(NEW.'.$column.') = 64 AND REGEXP_LIKE(NEW.'.$column.", '^[a-f0-9]{64}$', 'c'))"
            : '(length(NEW.'.$column.') = 64 AND NEW.'.$column." NOT GLOB '*[^a-f0-9]*')";
        $guards[] = self::guard($driver, $parent.'_insert', $wrap($parent), 'INSERT',
            'NOT '.$hash('service_hash').' OR NOT '.$hash('brief_hash').' OR EXISTS (SELECT 1 FROM '.$wrap($parent)
            .' WHERE id = NEW.id OR public_id = NEW.public_id OR (customer_account_id = NEW.customer_account_id AND creation_key = NEW.creation_key))');
        $guards[] = self::guard($driver, $events.'_insert', $wrap($events), 'INSERT',
            'NEW.number < 1 OR NEW.number > 1000 OR NEW.actor_kind NOT IN (\'staff\', \'buyer\') OR NOT '.$hash('request_hash').' OR NOT '.$hash('payload_hash')
            .' OR EXISTS (SELECT 1 FROM '.$wrap($events).' WHERE id = NEW.id OR public_id = NEW.public_id'
            .' OR (project_id = NEW.project_id AND (number = NEW.number OR request_key = NEW.request_key)))');
        $steps = [];
        $state = ['tables' => [], 'guards' => []];
        $states = [$state];
        $names = [$parent, $events];
        foreach ($definitions as $logical => $definition) {
            $blueprint = new Blueprint(DB::connection(), $logical);
            $blueprint->create();
            $definition($blueprint);
            $physical = $prefix.$logical;
            foreach ($blueprint->toSql() as $sql) {
                $steps[] = $sql;
                if ($driver === 'sqlite') {
                    if (str_starts_with($sql, 'create table ')) {
                        $state['tables'][$physical] = ['sql' => self::sqliteSql($sql), 'indexes' => []];
                    } elseif (preg_match('/\Acreate unique index "([^"]+)" on "([^"]+)"/', $sql, $match)) {
                        $state['tables'][$physical]['indexes'][$match[1]] = self::sqliteSql($sql);
                        $names[] = $match[1];
                    } else {
                        throw new LogicException('Unrecognized SQLite service installation statement.');
                    }
                } elseif (str_starts_with($sql, 'create table ')) {
                    $state['tables'][$physical] = self::mysqlDefinition($logical);
                } elseif (preg_match('/\Aalter table `([^`]+)` add constraint `([^`]+)` foreign key \(`([^`]+)`\) references `([^`]+)` \(`id`\) on delete restrict\z/', $sql, $match)) {
                    [$unused, $table, $name, $column, $target] = $match;
                    $state['tables'][$table]['foreign'][$name] = [$column, $target, 'id', 'NO ACTION', 'RESTRICT'];
                    $state['tables'][$table]['indexes'][$name] = ['columns' => [$column], 'unique' => false, 'implicit' => true];
                    $names[] = $name;
                } elseif (preg_match('/\Aalter table `([^`]+)` add unique `([^`]+)`\((.+)\)\z/', $sql, $match)) {
                    [$unused, $table, $name, $columns] = $match;
                    preg_match_all('/`([^`]+)`/', $columns, $columnMatches);
                    $columns = $columnMatches[1];
                    foreach ($state['tables'][$table]['indexes'] as $oldName => $index) {
                        if ($index['implicit'] && $index['columns'][0] === $columns[0]) {
                            unset($state['tables'][$table]['indexes'][$oldName]);
                        }
                    }
                    $state['tables'][$table]['indexes'][$name] = ['columns' => $columns, 'unique' => true, 'implicit' => false];
                    $names[] = $name;
                } else {
                    throw new LogicException('Unrecognized MySQL service installation statement.');
                }
                $states[] = self::canonical($state);
            }
        }
        foreach ($guards as $guard) {
            $steps[] = $guard['sql'];
            $state['guards'][$guard['name']] = $driver === 'sqlite'
                ? ['table' => $guard['table'], 'sql' => $guard['sql']]
                : ['table' => $guard['table'], 'event' => $guard['event'], 'body' => $guard['body'], 'environment' => self::mysqlTriggerEnvironment()];
            $names[] = $guard['name'];
            $states[] = self::canonical($state);
        }

        return [$steps, $states, array_values(array_unique($names))];
    }

    private static function guard(string $driver, string $name, string $wrappedTable, string $event, ?string $condition = null): array
    {
        $wrappedName = DB::connection()->getQueryGrammar()->wrap($name);
        $table = trim($wrappedTable, '`"');
        if ($driver === 'sqlite') {
            $sql = 'CREATE TRIGGER '.$wrappedName.' BEFORE '.$event.' ON '.$wrappedTable.($condition ? ' WHEN '.$condition : '')
                ." BEGIN SELECT RAISE(ABORT, 'Retain service project evidence'); END";
            $body = '';
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain service project evidence';";
            $body = 'BEGIN '.($condition ? 'IF '.$condition.' THEN '.$signal.' END IF;' : $signal).' END';
            $sql = 'CREATE TRIGGER '.$wrappedName.' BEFORE '.$event.' ON '.$wrappedTable.' FOR EACH ROW '.$body;
        }

        return compact('name', 'table', 'event', 'body', 'sql');
    }

    private static function mysqlDefinition(string $logical): array
    {
        $types = $logical === 'service_projects' ? [
            'id' => 'bigint unsigned', 'public_id' => 'char(36)', 'customer_account_id' => 'bigint unsigned',
            'service_version_id' => 'bigint unsigned', 'service_manifest' => 'longtext', 'service_hash' => 'char(64)',
            'brief' => 'longtext', 'brief_hash' => 'char(64)', 'creation_key' => 'char(36)', 'created_by' => 'bigint unsigned', 'created_at' => 'timestamp',
        ] : [
            'id' => 'bigint unsigned', 'public_id' => 'char(36)', 'project_id' => 'bigint unsigned', 'number' => 'int unsigned',
            'operation' => 'varchar(40)', 'actor_kind' => 'varchar(8)', 'actor_id' => 'bigint unsigned', 'request_key' => 'char(36)',
            'request_hash' => 'char(64)', 'payload' => 'longtext', 'payload_hash' => 'char(64)', 'created_at' => 'timestamp',
        ];
        $connection = DB::connection();
        $charset = $connection->getConfig('charset');
        $collation = $connection->getConfig('collation');
        $columns = [];
        foreach ($types as $name => $type) {
            $text = str_starts_with($type, 'char(') || str_starts_with($type, 'varchar(') || $type === 'longtext';
            $columns[] = [$name, $type, 'NO', null, $name === 'id' ? 'auto_increment' : '', $text ? $charset : null, $text ? $collation : null, '', ''];
        }

        return ['storage' => ['InnoDB', $collation, '', ''], 'columns' => $columns,
            'indexes' => ['PRIMARY' => ['columns' => ['id'], 'unique' => true, 'implicit' => false]], 'foreign' => []];
    }

    private static function mysqlTriggerEnvironment(): array
    {
        $pdo = DB::connection()->getPdo();
        $environment = $pdo->query('SELECT @@SESSION.sql_mode, @@SESSION.character_set_client, @@SESSION.collation_connection, CURRENT_USER()')->fetch(PDO::FETCH_NUM);
        $environment[] = $pdo->query('SELECT DEFAULT_COLLATION_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = DATABASE()')->fetchColumn();

        return $environment;
    }

    private static function canonical(array $state): array
    {
        foreach ($state['tables'] as &$table) {
            foreach (['indexes', 'foreign'] as $key) {
                if (isset($table[$key])) {
                    ksort($table[$key], SORT_STRING);
                }
            }
            if (isset($table['indexes'])) {
                foreach ($table['indexes'] as &$index) {
                    if (is_array($index)) {
                        unset($index['implicit']);
                    }
                }
                unset($index);
            }
        }
        unset($table);
        ksort($state['tables'], SORT_STRING);
        ksort($state['guards'], SORT_STRING);

        return $state;
    }

    private static function preflight(PDO $pdo, string $driver, string $prefix, array $states, array $names): int
    {
        $enforced = $driver === 'sqlite' ? (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() === 1
            : $pdo->query('SELECT @@SESSION.foreign_key_checks, @@SESSION.unique_checks')->fetch(PDO::FETCH_NUM) === [1, 1];
        if (! $enforced) {
            throw new LogicException('Service installation requires enforced foreign and unique keys.');
        }
        $tables = [$prefix.'service_projects', $prefix.'service_project_events'];
        $dependencies = [$prefix.'users', $prefix.'customer_accounts', $prefix.'service_draft_versions'];
        $actual = $driver === 'sqlite'
            ? self::sqliteState($pdo, $tables, $dependencies, $names)
            : self::mysqlState($pdo, $tables, $dependencies, $names);
        $actual = self::canonical($actual);
        $installed = array_search($actual, $states, true);
        if ($installed === false) {
            throw new LogicException('Service schema is not an exact owned installation prefix; nothing was changed.');
        }
        if ($installed !== count($states) - 1) {
            foreach (array_keys($actual['tables']) as $table) {
                if ($pdo->query('SELECT 1 FROM '.DB::connection()->getQueryGrammar()->wrap($table).' LIMIT 1')->fetchColumn() !== false) {
                    throw new LogicException('Incomplete service schema contains retained evidence; recovery refused.');
                }
            }
        }

        return $installed;
    }

    private static function references(?string $sql, array $names): bool
    {
        foreach ($names as $name) {
            if (preg_match('/(?<![a-zA-Z0-9_])'.preg_quote($name, '/').'(?![a-zA-Z0-9_])/i', $sql ?? '')) {
                return true;
            }
        }

        return false;
    }

    private static function sqliteSql(string $sql): string
    {
        // SQLite canonicalizes only the initial CREATE keywords in sqlite_master.
        return preg_replace_callback('/\Acreate (?:table|unique index|trigger) /i', fn (array $match): string => strtoupper($match[0]), $sql);
    }

    private static function sqliteState(PDO $pdo, array $tables, array $dependencies, array $names): array
    {
        $reserved = array_map(strtolower(...), $names);
        foreach ($pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC) as $object) {
            if (in_array(strtolower($object['name']), [...$reserved, ...array_map(strtolower(...), $dependencies)], true)
                || in_array(strtolower($object['tbl_name']), array_map(strtolower(...), $tables), true)
                || self::references($object['sql'], $tables)) {
                throw new LogicException('Service schema or dependency is temporarily shadowed.');
            }
        }
        $objects = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
        $byName = array_column($objects, null, 'name');
        foreach ($dependencies as $dependency) {
            $matches = array_values(array_filter($objects, fn (array $object): bool => strtolower($object['name']) === strtolower($dependency)));
            if (count($matches) !== 1 || $matches[0]['name'] !== $dependency) {
                throw new LogicException('Service dependency namespace changed.');
            }
            if (($byName[$dependency]['type'] ?? null) !== 'table') {
                throw new LogicException('Service dependency identity changed.');
            }
            $columns = $pdo->query('PRAGMA table_xinfo('.DB::connection()->getQueryGrammar()->wrap($dependency).')')->fetchAll(PDO::FETCH_ASSOC);
            $id = array_values(array_filter($columns, fn (array $column): bool => $column['name'] === 'id'));
            $primary = array_values(array_filter($columns, fn (array $column): bool => (int) $column['pk'] > 0));
            if (count($id) !== 1 || count($primary) !== 1 || strtolower($id[0]['type']) !== 'integer' || (int) $id[0]['pk'] !== 1 || (int) $id[0]['hidden'] !== 0) {
                throw new LogicException('Service dependency key changed.');
            }
        }
        $state = ['tables' => [], 'guards' => []];
        foreach ($objects as $object) {
            $name = $object['name'];
            $owned = in_array(strtolower($object['tbl_name']), array_map(strtolower(...), $tables), true);
            if (in_array(strtolower($name), $reserved, true) && ! in_array($name, $names, true)) {
                throw new LogicException('Service schema identity collision.');
            }
            if (! $owned && (in_array(strtolower($name), $reserved, true) || self::references($object['sql'], $tables))) {
                throw new LogicException('External service namespace or dependency collision.');
            }
            if (! $owned) {
                continue;
            }
            if (! in_array($name, $names, true) || ! in_array($object['tbl_name'], $tables, true)) {
                throw new LogicException('Unexpected service schema object.');
            }
            if ($object['type'] === 'table' && $name === $object['tbl_name']) {
                $state['tables'][$name] = ['sql' => $object['sql'], 'indexes' => []];
            } elseif ($object['type'] === 'index') {
                $state['tables'][$object['tbl_name']]['indexes'][$name] = $object['sql'];
            } elseif ($object['type'] === 'trigger') {
                $state['guards'][$name] = ['table' => $object['tbl_name'], 'sql' => $object['sql']];
            } else {
                throw new LogicException('Unexpected service schema object type.');
            }
        }
        // Reject incoming FK references even when CREATE SQL quotes/qualifies the target unusually.
        foreach ($objects as $object) {
            if ($object['type'] !== 'table' || in_array($object['name'], $tables, true) || str_starts_with($object['name'], 'sqlite_')) {
                continue;
            }
            foreach ($pdo->query('PRAGMA foreign_key_list('.DB::connection()->getQueryGrammar()->wrap($object['name']).')')->fetchAll(PDO::FETCH_ASSOC) as $foreign) {
                if (in_array(strtolower($foreign['table']), array_map(strtolower(...), $tables), true)) {
                    throw new LogicException('External dependency on service schema.');
                }
            }
        }

        return $state;
    }

    private static function mysqlState(PDO $pdo, array $tables, array $dependencies, array $names): array
    {
        $wrap = DB::connection()->getQueryGrammar()->wrap(...);
        foreach ([...$tables, ...$dependencies] as $table) {
            try {
                $shown = $pdo->query('SHOW CREATE TABLE '.$wrap($table))->fetch(PDO::FETCH_ASSOC);
                if (str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE')) {
                    throw new LogicException('Service schema or dependency is temporarily shadowed.');
                }
            } catch (PDOException $error) {
                if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
        }
        // Native dictionary collations can equate accent/case aliases; inspect them in SQL,
        // then require exact raw spelling before treating any object as owned.
        foreach ([['TABLES', 'TABLE_NAME', 'TABLE_SCHEMA'], ['TRIGGERS', 'TRIGGER_NAME', 'TRIGGER_SCHEMA'],
            ['STATISTICS', 'INDEX_NAME', 'TABLE_SCHEMA'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_NAME', 'TABLE_SCHEMA']] as [$catalog, $column, $scope]) {
            $reserved = implode(' UNION ALL ', array_fill(0, count($names), 'SELECT CAST(? AS CHAR CHARACTER SET utf8mb3) AS reserved_name'));
            $query = $pdo->prepare('SELECT t.'.$column.' AS object_name, r.reserved_name FROM ('.$reserved.') r'
                .' JOIN information_schema.'.$catalog.' t ON CONVERT(t.'.$column.' USING utf8mb3) COLLATE utf8mb3_general_ci = r.reserved_name COLLATE utf8mb3_general_ci'
                .' WHERE t.'.$scope.' = DATABASE()');
            $query->execute($names);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['object_name'] !== $row['reserved_name']) {
                    throw new LogicException('Service dictionary identity collision.');
                }
            }
        }
        $allTables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $byName = array_column($allTables, null, 'TABLE_NAME');
        foreach ($dependencies as $dependency) {
            $matches = array_values(array_filter($allTables, fn (array $table): bool => strtolower($table['TABLE_NAME']) === strtolower($dependency)));
            if (count($matches) !== 1 || $matches[0]['TABLE_NAME'] !== $dependency) {
                throw new LogicException('Service dependency namespace changed.');
            }
            $table = $byName[$dependency] ?? null;
            if (! $table || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
                throw new LogicException('Service requires exact transactional dependency tables.');
            }
            $q = $pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$dependency, 'id']);
            if ($q->fetch(PDO::FETCH_NUM) !== ['bigint unsigned', 'NO', 'auto_increment']) {
                throw new LogicException('Service dependency key changed.');
            }
            $q = $pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
            $q->execute([$dependency, 'PRIMARY']);
            if ($q->fetchAll(PDO::FETCH_NUM) !== [['id', 0]]) {
                throw new LogicException('Service dependency primary key changed.');
            }
        }
        $state = ['tables' => [], 'guards' => []];
        foreach ($allTables as $table) {
            if (in_array($table['TABLE_NAME'], $names, true) && ! in_array($table['TABLE_NAME'], $tables, true)) {
                throw new LogicException('Service namespace occupied by an external table.');
            }
            if (! in_array($table['TABLE_NAME'], $tables, true)) {
                continue;
            }
            if ($table['TABLE_TYPE'] !== 'BASE TABLE') {
                throw new LogicException('Service table identity changed.');
            }
            $name = $table['TABLE_NAME'];
            $q = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_COMMENT, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
            $q->execute([$name]);
            $state['tables'][$name] = ['storage' => [$table['ENGINE'], $table['TABLE_COLLATION'], $table['CREATE_OPTIONS'], $table['TABLE_COMMENT']], 'columns' => $q->fetchAll(PDO::FETCH_NUM), 'indexes' => [], 'foreign' => []];
        }
        $indexes = $pdo->query('SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX, INDEX_TYPE, COLLATION, SUB_PART, EXPRESSION, IS_VISIBLE, INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($indexes as $index) {
            if (! in_array($index['TABLE_NAME'], $tables, true)) {
                if (in_array($index['INDEX_NAME'], $names, true)) {
                    throw new LogicException('Service index namespace occupied externally.');
                }

                continue;
            }
            if ($index['INDEX_TYPE'] !== 'BTREE' || $index['COLLATION'] !== 'A' || $index['SUB_PART'] !== null || $index['EXPRESSION'] !== null || $index['IS_VISIBLE'] !== 'YES' || $index['INDEX_COMMENT'] !== '') {
                throw new LogicException('Service index metadata changed.');
            }
            $entry = &$state['tables'][$index['TABLE_NAME']]['indexes'][$index['INDEX_NAME']];
            $entry ??= ['columns' => [], 'unique' => (int) $index['NON_UNIQUE'] === 0];
            if ((int) $index['SEQ_IN_INDEX'] !== count($entry['columns']) + 1) {
                throw new LogicException('Service index column order changed.');
            }
            $entry['columns'][] = $index['COLUMN_NAME'];
            unset($entry);
        }
        $constraints = $pdo->query('SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($constraints as $constraint) {
            if (! in_array($constraint['TABLE_NAME'], $tables, true)) {
                if (in_array($constraint['CONSTRAINT_NAME'], $names, true)) {
                    throw new LogicException('Service constraint namespace occupied externally.');
                }

                continue;
            }
            if (! in_array($constraint['CONSTRAINT_TYPE'], ['PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY'], true)
                || ($constraint['CONSTRAINT_TYPE'] !== 'FOREIGN KEY' && ! isset($state['tables'][$constraint['TABLE_NAME']]['indexes'][$constraint['CONSTRAINT_NAME']]))) {
                throw new LogicException('Unexpected service constraint.');
            }
        }
        $foreign = $pdo->query('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreign as $key) {
            if (! in_array($key['TABLE_NAME'], $tables, true)) {
                if (in_array(strtolower($key['REFERENCED_TABLE_NAME']), array_map(strtolower(...), $tables), true)) {
                    throw new LogicException('External dependency on service schema.');
                }

                continue;
            }
            if ($key['REFERENCED_TABLE_SCHEMA'] !== DB::getDatabaseName() || (int) $key['ORDINAL_POSITION'] !== 1
                || isset($state['tables'][$key['TABLE_NAME']]['foreign'][$key['CONSTRAINT_NAME']])) {
                throw new LogicException('Service foreign key metadata changed.');
            }
            $state['tables'][$key['TABLE_NAME']]['foreign'][$key['CONSTRAINT_NAME']] = [$key['COLUMN_NAME'], $key['REFERENCED_TABLE_NAME'], $key['REFERENCED_COLUMN_NAME'], $key['UPDATE_RULE'], $key['DELETE_RULE']];
        }
        foreach ($pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT, ACTION_ORDER, SQL_MODE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DEFINER, DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $guard) {
            $owned = in_array($guard['EVENT_OBJECT_TABLE'], $tables, true);
            if (! $owned && (in_array($guard['TRIGGER_NAME'], $names, true) || self::references($guard['ACTION_STATEMENT'], $tables))) {
                throw new LogicException('External service trigger namespace or dependency.');
            }
            if ($owned) {
                if ($guard['ACTION_TIMING'] !== 'BEFORE' || (int) $guard['ACTION_ORDER'] !== 1) {
                    throw new LogicException('Service trigger timing changed.');
                }
                $state['guards'][$guard['TRIGGER_NAME']] = ['table' => $guard['EVENT_OBJECT_TABLE'], 'event' => $guard['EVENT_MANIPULATION'], 'body' => $guard['ACTION_STATEMENT'],
                    'environment' => [$guard['SQL_MODE'], $guard['CHARACTER_SET_CLIENT'], $guard['COLLATION_CONNECTION'], $guard['DEFINER'], $guard['DATABASE_COLLATION']]];
            }
        }
        foreach ($pdo->query('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $view) {
            if (self::references($view['VIEW_DEFINITION'], $tables)) {
                throw new LogicException('External service view dependency.');
            }
        }

        return $state;
    }
}
