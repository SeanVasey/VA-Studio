<?php

namespace App\Domain\Grants\Paid;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;

/** Append only exact owned installation prefixes; never repair drift or discard evidence. */
final class PaidGrantSchema
{
    public static function install(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $prefix = $connection->getTablePrefix();
        if (! in_array($driver, ['sqlite', 'mysql'], true) || ! preg_match('/\A[a-zA-Z0-9_]{0,16}\z/D', $prefix)) {
            throw new LogicException('Paid grants require SQLite or MySQL and a bounded safe prefix.');
        }
        $connection->getSchemaBuilder();
        [$steps, $states, $names] = self::plan($driver, $prefix);
        $pdo = $connection->getPdo();
        $installed = self::preflight($pdo, $driver, $prefix, $states, $names);
        // Operative migration requires application writers and competing migrators stopped.
        // Every framework DDL is independently committed on MySQL and independently retryable.
        foreach (array_slice($steps, $installed) as $offset => $sql) {
            if (DB::connection()->getPdo() !== $pdo || DB::connection()->getTablePrefix() !== $prefix) {
                throw new LogicException('Paid grant migration connection changed before a statement.');
            }
            if (self::preflight($pdo, $driver, $prefix, $states, $names) !== $installed + $offset) {
                throw new LogicException('Paid grant installation changed during recovery.');
            }
            DB::unprepared($sql);
        }
        if (DB::connection()->getPdo() !== $pdo || DB::connection()->getTablePrefix() !== $prefix
            || self::preflight($pdo, $driver, $prefix, $states, $names) !== count($steps)) {
            throw new LogicException('Paid grant installation did not finish exactly.');
        }
    }

    private static function plan(string $driver, string $prefix): array
    {
        $definitions = [];
        $guards = [];
        $wrap = DB::connection()->getQueryGrammar()->wrap(...);
        foreach (self::specs() as $logical => $spec) {
            $physical = $prefix.$logical;
            $definitions[$logical] = function (Blueprint $table) use ($spec, $physical): void {
                $table->engine = 'InnoDB';
                $table->id();
                foreach ($spec['columns'] as $name => $type) {
                    $nullable = str_ends_with($type, '?');
                    $type = rtrim($type, '?');
                    $column = match ($type) {
                        'bigint unsigned' => $table->unsignedBigInteger($name),
                        'int unsigned' => $table->unsignedInteger($name),
                        'longtext' => $table->longText($name),
                        'timestamp' => $table->timestamp($name),
                        default => preg_match('/\Achar\((\d+)\)\z/', $type, $match)
                            ? $table->char($name, (int) $match[1])
                            : $table->string($name, (int) substr($type, 8, -1)),
                    };
                    if ($nullable) {
                        $column->nullable();
                    }
                }
                foreach ($spec['foreign'] as $name => [$column, $target]) {
                    $table->foreign($column, $physical.'_'.$name)->references('id')->on($target)->restrictOnDelete();
                }
                foreach ($spec['unique'] as $name => $columns) {
                    $table->unique($columns, $physical.'_'.$name);
                }
            };
            if ($logical !== 'paid_document_work') {
                $guards[] = self::guard($driver, $physical.'_immutable', $wrap($physical), 'UPDATE');
            } else {
                $same = $driver === 'mysql' ? '<=>' : 'IS';
                $identity = implode(' AND ', array_map(fn ($name): string => 'NEW.'.$name.' '.$same.' OLD.'.$name, ['id', 'origin_id', 'created_at']));
                $original = 'EXISTS (SELECT 1 FROM '.$wrap($prefix.'paid_originals').' WHERE origin_id = OLD.origin_id AND claim_id = OLD.claim_id)';
                $attempt = $driver === 'sqlite' ? "typeof(NEW.attempts) = 'integer' AND " : '';
                $allowed = $identity.' AND '.$attempt."OLD.state <> 'complete' AND NEW.attempts <= 5 AND ((NEW.state = 'claimed' AND NEW.attempts = OLD.attempts + 1 AND NEW.claim_id IS NOT NULL AND ".self::uuid($driver, 'claim_id').' AND NEW.expires_at IS NOT NULL AND NEW.expires_at > NEW.created_at AND NOT '.$original.") OR (OLD.state = 'claimed' AND NEW.attempts = OLD.attempts AND NEW.claim_id = OLD.claim_id AND NEW.expires_at = OLD.expires_at AND ((NEW.state = 'complete' AND ".$original.") OR (NEW.state = 'failed' AND NOT ".$original.'))))';
                $guards[] = self::guard($driver, $physical.'_immutable', $wrap($physical), 'UPDATE', 'NOT COALESCE(('.$allowed.'), 0)');
            }
            $guards[] = self::guard($driver, $physical.'_retain', $wrap($physical), 'DELETE');
            $conditions = ['EXISTS (SELECT 1 FROM '.$wrap($physical).' WHERE id = NEW.id)'];
            foreach ($spec['unique'] as $columns) {
                $equal = implode(' AND ', array_map(fn (string $column): string => $column.' = NEW.'.$column, $columns));
                $conditions[] = 'EXISTS (SELECT 1 FROM '.$wrap($physical).' WHERE '.$equal.')';
            }
            foreach ($spec['columns'] as $name => $type) {
                if (str_ends_with($name, '_hash')) {
                    $conditions[] = $driver === 'mysql'
                        ? 'NOT (CHAR_LENGTH(NEW.'.$name.') = 64 AND REGEXP_LIKE(NEW.'.$name.", '^[a-f0-9]{64}$', 'c'))"
                        : 'NOT (length(NEW.'.$name.') = 64 AND NEW.'.$name." NOT GLOB '*[^a-f0-9]*')";
                }
            }
            if ($logical === 'paid_document_work') {
                $conditions[] = "NEW.state <> 'pending' OR NEW.attempts <> 0 OR NEW.claim_id IS NOT NULL OR NEW.expires_at IS NOT NULL";
            }
            foreach ($spec['columns'] as $name => $type) {
                if ($type === 'char(36)') {
                    $conditions[] = 'NOT ('.self::uuid($driver, $name).')';
                }
                if (in_array($type, ['bigint unsigned', 'int unsigned'], true)) {
                    $conditions[] = ($driver === 'sqlite' ? 'typeof(NEW.'.$name.") <> 'integer' OR " : '').'NEW.'.$name.($name === 'attempts' ? ' < 0 OR NEW.'.$name.' > 5' : ' < 1 OR NEW.'.$name.' > 21474836470');
                }
                if ($name === 'payload') {
                    $conditions[] = 'length(NEW.payload) < 1 OR length(NEW.payload) > 4194304';
                }
            }
            if ($logical === 'paid_order_origins') {
                $conditions[] = "NEW.producer <> 'production_checkout_v1' OR NEW.line_count < 1 OR NEW.line_count > 10";
            }
            if ($logical === 'paid_grant_origins') {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM '.$wrap($prefix.'paid_order_origins').' WHERE id = NEW.batch_id AND NEW.position BETWEEN 1 AND line_count)';
            }
            if ($logical === 'paid_originals') {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM '.$wrap($prefix.'paid_document_work')." WHERE origin_id = NEW.origin_id AND state = 'claimed' AND claim_id = NEW.claim_id)";
            }
            if ($logical === 'paid_fulfillments') {
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM '.$wrap($prefix.'paid_order_origins').' b WHERE b.id = NEW.batch_id AND b.line_count = (SELECT COUNT(*) FROM '.$wrap($prefix.'paid_grant_origins').' g WHERE g.batch_id = b.id) AND NOT EXISTS (SELECT 1 FROM '.$wrap($prefix.'paid_grant_origins').' g LEFT JOIN '.$wrap($prefix.'paid_document_work').' w ON w.origin_id = g.id LEFT JOIN '.$wrap($prefix.'paid_originals')." o ON o.origin_id = g.id WHERE g.batch_id = b.id AND (w.state IS NULL OR w.state <> 'complete' OR o.id IS NULL OR w.claim_id <> o.claim_id)))";
            }
            if ($logical === 'paid_authorizations') {
                $conditions[] = "NEW.target NOT IN ('contract', 'master_wav', 'download_mp3', 'stems_zip') OR NEW.expires_at <= NEW.created_at";
                $conditions[] = 'NOT EXISTS (SELECT 1 FROM '.$wrap($prefix.'paid_grant_origins').' g JOIN '.$wrap($prefix.'paid_order_origins').' b ON b.id = g.batch_id JOIN '.$wrap($prefix.'paid_fulfillments').' f ON f.batch_id = b.id WHERE g.id = NEW.origin_id AND b.account_id = NEW.account_id)';
            }
            $guards[] = self::guard($driver, $physical.'_insert', $wrap($physical), 'INSERT', implode(' OR ', $conditions));
        }
        $steps = [];
        $state = ['tables' => [], 'guards' => []];
        $states = [$state];
        $names = array_map(fn (string $name): string => $prefix.$name, array_keys(self::specs()));
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
                        throw new LogicException('Unrecognized SQLite paid grant installation statement.');
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
                    throw new LogicException('Unrecognized MySQL paid grant installation statement.');
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

    private static function uuid(string $driver, string $column): string
    {
        if ($driver === 'mysql') {
            return 'REGEXP_LIKE(NEW.'.$column.", '^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$', 'c')";
        }

        return 'length(NEW.'.$column.') = 36 AND length(replace(NEW.'.$column.", '-', '')) = 32 AND NEW.".$column." NOT GLOB '*[^a-f0-9-]*' AND substr(NEW.".$column.", 15, 1) = '4' AND substr(NEW.".$column.", 20, 1) IN ('8','9','a','b') AND substr(NEW.".$column.", 9, 1) = '-' AND substr(NEW.".$column.", 14, 1) = '-' AND substr(NEW.".$column.", 19, 1) = '-' AND substr(NEW.".$column.", 24, 1) = '-'";
    }

    public static function specs(): array
    {
        return [
            'paid_order_origins' => ['columns' => ['public_id' => 'char(36)', 'account_id' => 'bigint unsigned', 'actor_id' => 'bigint unsigned', 'producer' => 'varchar(32)', 'order_public_id' => 'char(36)', 'order_hash' => 'char(64)', 'payment_public_id' => 'char(36)', 'payment_hash' => 'char(64)', 'line_count' => 'int unsigned', 'payload' => 'longtext', 'payload_hash' => 'char(64)', 'created_at' => 'timestamp'],
                'foreign' => ['account' => ['account_id', 'customer_accounts'], 'actor' => ['actor_id', 'users']], 'unique' => ['public' => ['public_id'], 'producer_order' => ['producer', 'order_public_id']]],
            'paid_grant_origins' => ['columns' => ['public_id' => 'char(36)', 'batch_id' => 'bigint unsigned', 'position' => 'int unsigned', 'line_public_id' => 'char(36)', 'line_hash' => 'char(64)', 'source_hash' => 'char(64)', 'payload' => 'longtext', 'payload_hash' => 'char(64)', 'created_at' => 'timestamp'],
                'foreign' => ['batch' => ['batch_id', 'paid_order_origins']], 'unique' => ['public' => ['public_id'], 'position' => ['batch_id', 'position'], 'line_once' => ['batch_id', 'line_public_id']]],
            'paid_document_work' => ['columns' => ['origin_id' => 'bigint unsigned', 'state' => 'varchar(12)', 'attempts' => 'int unsigned', 'claim_id' => 'char(36)?', 'expires_at' => 'timestamp?', 'created_at' => 'timestamp'],
                'foreign' => ['origin' => ['origin_id', 'paid_grant_origins']], 'unique' => ['origin_once' => ['origin_id']]],
            'paid_originals' => ['columns' => ['origin_id' => 'bigint unsigned', 'claim_id' => 'char(36)', 'payload' => 'longtext', 'payload_hash' => 'char(64)', 'created_at' => 'timestamp'],
                'foreign' => ['origin' => ['origin_id', 'paid_grant_origins']], 'unique' => ['origin_once' => ['origin_id'], 'claim_once' => ['claim_id']]],
            'paid_fulfillments' => ['columns' => ['batch_id' => 'bigint unsigned', 'payload' => 'longtext', 'payload_hash' => 'char(64)', 'created_at' => 'timestamp'],
                'foreign' => ['batch' => ['batch_id', 'paid_order_origins']], 'unique' => ['batch_once' => ['batch_id']]],
            'paid_authorizations' => ['columns' => ['public_id' => 'char(36)', 'origin_id' => 'bigint unsigned', 'account_id' => 'bigint unsigned', 'request_key' => 'char(36)', 'request_hash' => 'char(64)', 'token_hash' => 'char(64)', 'target' => 'varchar(24)', 'payload' => 'longtext', 'payload_hash' => 'char(64)', 'created_at' => 'timestamp', 'expires_at' => 'timestamp'],
                'foreign' => ['origin' => ['origin_id', 'paid_grant_origins'], 'account' => ['account_id', 'customer_accounts']], 'unique' => ['public' => ['public_id'], 'request' => ['account_id', 'request_key'], 'token_once' => ['token_hash']]],
            'paid_redemptions' => ['columns' => ['authorization_id' => 'bigint unsigned', 'created_at' => 'timestamp'],
                'foreign' => ['authorization' => ['authorization_id', 'paid_authorizations']], 'unique' => ['authorization_once' => ['authorization_id']]],
        ];
    }

    private static function guard(string $driver, string $name, string $wrappedTable, string $event, ?string $condition = null): array
    {
        $wrappedName = DB::connection()->getQueryGrammar()->wrap($name);
        $table = trim($wrappedTable, '`"');
        if ($driver === 'sqlite') {
            $sql = 'CREATE TRIGGER '.$wrappedName.' BEFORE '.$event.' ON '.$wrappedTable.($condition ? ' WHEN '.$condition : '')
                ." BEGIN SELECT RAISE(ABORT, 'Retain paid grant evidence'); END";
            $body = '';
        } else {
            $signal = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain paid grant evidence';";
            $body = 'BEGIN '.($condition ? 'IF '.$condition.' THEN '.$signal.' END IF;' : $signal).' END';
            $sql = 'CREATE TRIGGER '.$wrappedName.' BEFORE '.$event.' ON '.$wrappedTable.' FOR EACH ROW '.$body;
        }

        return compact('name', 'table', 'event', 'body', 'sql');
    }

    private static function mysqlDefinition(string $logical): array
    {
        $types = ['id' => 'bigint unsigned', ...self::specs()[$logical]['columns']];
        $connection = DB::connection();
        $charset = $connection->getConfig('charset');
        $collation = $connection->getConfig('collation');
        $columns = [];
        foreach ($types as $name => $type) {
            $nullable = str_ends_with($type, '?');
            $type = rtrim($type, '?');
            $text = str_starts_with($type, 'char(') || str_starts_with($type, 'varchar(') || $type === 'longtext';
            $columns[] = [$name, $type, $nullable ? 'YES' : 'NO', null, $name === 'id' ? 'auto_increment' : '', $text ? $charset : null, $text ? $collation : null, '', ''];
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
            throw new LogicException('Paid grant installation requires enforced foreign and unique keys.');
        }
        $tables = array_map(fn (string $name): string => $prefix.$name, array_keys(self::specs()));
        $dependencies = array_map(fn (string $name): string => $prefix.$name, ['users', 'customer_accounts']);
        $actual = $driver === 'sqlite'
            ? self::sqliteState($pdo, $tables, $dependencies, $names)
            : self::mysqlState($pdo, $tables, $dependencies, $names);
        $actual = self::canonical($actual);
        $installed = array_search($actual, $states, true);
        if ($installed === false) {
            throw new LogicException('Paid grant schema is not an exact owned installation prefix; nothing was changed.');
        }
        if ($installed !== count($states) - 1) {
            foreach (array_keys($actual['tables']) as $table) {
                if ($pdo->query('SELECT 1 FROM '.DB::connection()->getQueryGrammar()->wrap($table).' LIMIT 1')->fetchColumn() !== false) {
                    throw new LogicException('Incomplete paid grant schema contains retained evidence; recovery refused.');
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
                throw new LogicException('Paid grant schema or dependency is temporarily shadowed.');
            }
        }
        $objects = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
        $byName = array_column($objects, null, 'name');
        foreach ($dependencies as $dependency) {
            $matches = array_values(array_filter($objects, fn (array $object): bool => strtolower($object['name']) === strtolower($dependency)));
            if (count($matches) !== 1 || $matches[0]['name'] !== $dependency) {
                throw new LogicException('Paid grant dependency namespace changed.');
            }
            if (($byName[$dependency]['type'] ?? null) !== 'table') {
                throw new LogicException('Paid grant dependency identity changed.');
            }
            $columns = $pdo->query('PRAGMA table_xinfo('.DB::connection()->getQueryGrammar()->wrap($dependency).')')->fetchAll(PDO::FETCH_ASSOC);
            $id = array_values(array_filter($columns, fn (array $column): bool => $column['name'] === 'id'));
            $primary = array_values(array_filter($columns, fn (array $column): bool => (int) $column['pk'] > 0));
            if (count($id) !== 1 || count($primary) !== 1 || strtolower($id[0]['type']) !== 'integer' || (int) $id[0]['pk'] !== 1 || (int) $id[0]['hidden'] !== 0) {
                throw new LogicException('Paid grant dependency key changed.');
            }
        }
        $state = ['tables' => [], 'guards' => []];
        foreach ($objects as $object) {
            $name = $object['name'];
            $owned = in_array(strtolower($object['tbl_name']), array_map(strtolower(...), $tables), true);
            if (in_array(strtolower($name), $reserved, true) && ! in_array($name, $names, true)) {
                throw new LogicException('Paid grant schema identity collision.');
            }
            if (! $owned && (in_array(strtolower($name), $reserved, true) || self::references($object['sql'], $tables))) {
                throw new LogicException('External paid namespace or dependency collision.');
            }
            if (! $owned) {
                continue;
            }
            if (! in_array($name, $names, true) || ! in_array($object['tbl_name'], $tables, true)) {
                throw new LogicException('Unexpected paid grant schema object.');
            }
            if ($object['type'] === 'table' && $name === $object['tbl_name']) {
                $state['tables'][$name] = ['sql' => $object['sql'], 'indexes' => []];
            } elseif ($object['type'] === 'index') {
                $state['tables'][$object['tbl_name']]['indexes'][$name] = $object['sql'];
            } elseif ($object['type'] === 'trigger') {
                $state['guards'][$name] = ['table' => $object['tbl_name'], 'sql' => $object['sql']];
            } else {
                throw new LogicException('Unexpected paid grant schema object type.');
            }
        }
        // Reject incoming FK references even when CREATE SQL quotes/qualifies the target unusually.
        foreach ($objects as $object) {
            if ($object['type'] !== 'table' || in_array($object['name'], $tables, true) || str_starts_with($object['name'], 'sqlite_')) {
                continue;
            }
            foreach ($pdo->query('PRAGMA foreign_key_list('.DB::connection()->getQueryGrammar()->wrap($object['name']).')')->fetchAll(PDO::FETCH_ASSOC) as $foreign) {
                if (in_array(strtolower($foreign['table']), array_map(strtolower(...), $tables), true)) {
                    throw new LogicException('External dependency on paid grant schema.');
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
                    throw new LogicException('Paid grant schema or dependency is temporarily shadowed.');
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
            ['STATISTICS', 'INDEX_NAME', 'TABLE_SCHEMA'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_NAME', 'TABLE_SCHEMA'], ['ROUTINES', 'ROUTINE_NAME', 'ROUTINE_SCHEMA']] as [$catalog, $column, $scope]) {
            $reserved = implode(' UNION ALL ', array_fill(0, count($names), 'SELECT CAST(? AS CHAR CHARACTER SET utf8mb3) AS reserved_name'));
            $query = $pdo->prepare('SELECT t.'.$column.' AS object_name, r.reserved_name FROM ('.$reserved.') r'
                .' JOIN information_schema.'.$catalog.' t ON CONVERT(t.'.$column.' USING utf8mb3) COLLATE utf8mb3_general_ci = r.reserved_name COLLATE utf8mb3_general_ci'
                .' WHERE t.'.$scope.' = DATABASE()');
            $query->execute($names);
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['object_name'] !== $row['reserved_name']) {
                    throw new LogicException('Paid dictionary identity collision.');
                }
            }
        }
        $allTables = $pdo->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $byName = array_column($allTables, null, 'TABLE_NAME');
        foreach ($dependencies as $dependency) {
            $matches = array_values(array_filter($allTables, fn (array $table): bool => strtolower($table['TABLE_NAME']) === strtolower($dependency)));
            if (count($matches) !== 1 || $matches[0]['TABLE_NAME'] !== $dependency) {
                throw new LogicException('Paid grant dependency namespace changed.');
            }
            $table = $byName[$dependency] ?? null;
            if (! $table || $table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB') {
                throw new LogicException('Paid grants require exact transactional dependency tables.');
            }
            $q = $pdo->prepare('SELECT COLUMN_TYPE, IS_NULLABLE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $q->execute([$dependency, 'id']);
            if ($q->fetch(PDO::FETCH_NUM) !== ['bigint unsigned', 'NO', 'auto_increment']) {
                throw new LogicException('Paid grant dependency key changed.');
            }
            $q = $pdo->prepare('SELECT COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? ORDER BY SEQ_IN_INDEX');
            $q->execute([$dependency, 'PRIMARY']);
            if ($q->fetchAll(PDO::FETCH_NUM) !== [['id', 0]]) {
                throw new LogicException('Paid grant dependency primary key changed.');
            }
        }
        $state = ['tables' => [], 'guards' => []];
        foreach ($allTables as $table) {
            if (in_array($table['TABLE_NAME'], $names, true) && ! in_array($table['TABLE_NAME'], $tables, true)) {
                throw new LogicException('Paid grant namespace occupied by an external table.');
            }
            if (! in_array($table['TABLE_NAME'], $tables, true)) {
                continue;
            }
            if ($table['TABLE_TYPE'] !== 'BASE TABLE') {
                throw new LogicException('Paid table identity changed.');
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
                    throw new LogicException('Paid grant index namespace occupied externally.');
                }

                continue;
            }
            if ($index['INDEX_TYPE'] !== 'BTREE' || $index['COLLATION'] !== 'A' || $index['SUB_PART'] !== null || $index['EXPRESSION'] !== null || $index['IS_VISIBLE'] !== 'YES' || $index['INDEX_COMMENT'] !== '') {
                throw new LogicException('Paid grant index metadata changed.');
            }
            $entry = &$state['tables'][$index['TABLE_NAME']]['indexes'][$index['INDEX_NAME']];
            $entry ??= ['columns' => [], 'unique' => (int) $index['NON_UNIQUE'] === 0];
            if ((int) $index['SEQ_IN_INDEX'] !== count($entry['columns']) + 1) {
                throw new LogicException('Paid grant index column order changed.');
            }
            $entry['columns'][] = $index['COLUMN_NAME'];
            unset($entry);
        }
        $constraints = $pdo->query('SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($constraints as $constraint) {
            if (! in_array($constraint['TABLE_NAME'], $tables, true)) {
                if (in_array($constraint['CONSTRAINT_NAME'], $names, true)) {
                    throw new LogicException('Paid grant constraint namespace occupied externally.');
                }

                continue;
            }
            if (! in_array($constraint['CONSTRAINT_TYPE'], ['PRIMARY KEY', 'UNIQUE', 'FOREIGN KEY'], true)
                || ($constraint['CONSTRAINT_TYPE'] !== 'FOREIGN KEY' && ! isset($state['tables'][$constraint['TABLE_NAME']]['indexes'][$constraint['CONSTRAINT_NAME']]))) {
                throw new LogicException('Unexpected paid constraint.');
            }
        }
        $foreign = $pdo->query('SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, k.ORDINAL_POSITION, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME WHERE k.TABLE_SCHEMA = DATABASE() ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($foreign as $key) {
            if (! in_array($key['TABLE_NAME'], $tables, true)) {
                if (in_array(strtolower($key['REFERENCED_TABLE_NAME']), array_map(strtolower(...), $tables), true)) {
                    throw new LogicException('External dependency on paid grant schema.');
                }

                continue;
            }
            if ($key['REFERENCED_TABLE_SCHEMA'] !== DB::getDatabaseName() || (int) $key['ORDINAL_POSITION'] !== 1
                || isset($state['tables'][$key['TABLE_NAME']]['foreign'][$key['CONSTRAINT_NAME']])) {
                throw new LogicException('Paid grant foreign key metadata changed.');
            }
            $state['tables'][$key['TABLE_NAME']]['foreign'][$key['CONSTRAINT_NAME']] = [$key['COLUMN_NAME'], $key['REFERENCED_TABLE_NAME'], $key['REFERENCED_COLUMN_NAME'], $key['UPDATE_RULE'], $key['DELETE_RULE']];
        }
        foreach ($pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT, ACTION_ORDER, SQL_MODE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION, DEFINER, DATABASE_COLLATION FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $guard) {
            $owned = in_array($guard['EVENT_OBJECT_TABLE'], $tables, true);
            if (! $owned && (in_array($guard['TRIGGER_NAME'], $names, true) || self::references($guard['ACTION_STATEMENT'], $tables))) {
                throw new LogicException('External paid trigger namespace or dependency.');
            }
            if ($owned) {
                if ($guard['ACTION_TIMING'] !== 'BEFORE' || (int) $guard['ACTION_ORDER'] !== 1) {
                    throw new LogicException('Paid grant trigger timing changed.');
                }
                $state['guards'][$guard['TRIGGER_NAME']] = ['table' => $guard['EVENT_OBJECT_TABLE'], 'event' => $guard['EVENT_MANIPULATION'], 'body' => $guard['ACTION_STATEMENT'],
                    'environment' => [$guard['SQL_MODE'], $guard['CHARACTER_SET_CLIENT'], $guard['COLLATION_CONNECTION'], $guard['DEFINER'], $guard['DATABASE_COLLATION']]];
            }
        }
        foreach ($pdo->query('SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $view) {
            if (self::references($view['VIEW_DEFINITION'], $tables)) {
                throw new LogicException('External paid view dependency.');
            }
        }

        foreach ($pdo->query('SELECT ROUTINE_NAME, ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC) as $routine) {
            if (in_array(strtolower($routine['ROUTINE_NAME']), array_map(strtolower(...), $names), true)
                || $routine['ROUTINE_DEFINITION'] === null || self::references($routine['ROUTINE_DEFINITION'], $tables)) {
                throw new LogicException('External paid routine identity or unreadable dependency.');
            }
        }

        return $state;
    }
}
