<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use LogicException;
use PDO;
use PDOException;

/** Exact empty DDL-prefix recovery; ownership admission performs no application/query callbacks. */
final class IdentityMigrationOwnership
{
    public function inspect(PDO $pdo, string $driver): array
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver) {
            $this->reject();
        }
        $this->dependencies($pdo, $driver);
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

    /** Retained foundation metadata is a dependency, never something this migration repairs or adopts. */
    private function dependencies(PDO $pdo, string $driver): void
    {
        if ((int) $pdo->query($driver === 'sqlite' ? 'PRAGMA foreign_keys' : 'SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
            $this->reject();
        }
        $guards = $this->parentGuards($driver);
        $namespace = ['users' => ['table', 'users'], 'customer_accounts' => ['table', 'customer_accounts'], 'quote_owners' => ['table', 'quote_owners']];
        foreach ($guards as $name => $guard) {
            $namespace[$name] = ['trigger', $guard['table']];
        }
        foreach ($namespace as $name => [$kind, $owner]) {
            if ($driver === 'sqlite') {
                if ($this->query($pdo, 'SELECT name FROM sqlite_temp_master WHERE name COLLATE NOCASE=? OR tbl_name COLLATE NOCASE=?', [$name, $name]) !== []
                    || $this->query($pdo, 'SELECT name,type,tbl_name FROM main.sqlite_master WHERE name COLLATE NOCASE=?', [$name]) !== [['name' => $name, 'type' => $kind, 'tbl_name' => $owner]]) {
                    $this->reject();
                }
            } else {
                $objects = [...$this->query($pdo, "SELECT TABLE_NAME name,'table' type,TABLE_NAME tbl_name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$name]),
                    ...$this->query($pdo, "SELECT TRIGGER_NAME name,'trigger' type,EVENT_OBJECT_TABLE tbl_name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?", [$name])];
                if ($objects !== [['name' => $name, 'type' => $kind, 'tbl_name' => $owner]]) {
                    $this->reject();
                }
                foreach ([['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME'],
                    ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$dictionary, $schema, $column]) {
                    if ($this->query($pdo, "SELECT $column FROM information_schema.$dictionary WHERE $schema=DATABASE() AND $column=?", [$name]) !== []) {
                        $this->reject();
                    }
                }
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_ASSOC);
                    if ($kind !== 'table' || str_contains(strtoupper((string) ($shown['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                        $this->reject();
                    }
                } catch (PDOException $error) {
                    if (($error->errorInfo[1] ?? null) !== 1146 || $kind === 'table') {
                        throw $error;
                    }
                }
            }
        }
        if ($driver === 'sqlite') {
            $sql = [
                'users' => 'CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime, "is_admin" tinyint(1) not null default \'0\', "app_authentication_secret" text, "app_authentication_recovery_codes" text)',
                'customer_accounts' => 'CREATE TABLE "customer_accounts" ("id" integer primary key autoincrement not null, "public_id" varchar not null, "user_id" integer not null, "owner_key" varchar not null, "active" tinyint(1) not null, "access_version" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete restrict, foreign key("owner_key") references "quote_owners"("owner_key") on delete restrict)',
                'quote_owners' => 'CREATE TABLE "quote_owners" ("owner_key" varchar not null, primary key ("owner_key"))',
            ];
            foreach ($sql as $table => $definition) {
                if ($this->query($pdo, "SELECT sql FROM main.sqlite_master WHERE type='table' AND name=?", [$table]) !== [['sql' => $definition]]) {
                    $this->reject();
                }
                $expected = $this->parentIndexes($table);
                unset($expected['PRIMARY']);
                if ($table === 'quote_owners') {
                    $expected = ['sqlite_autoindex_quote_owners_1' => ['owner_key']];
                }
                $actual = [];
                foreach ($pdo->query('PRAGMA main.index_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                    if (! isset($expected[$index['name']]) || (int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) {
                        $this->reject();
                    }
                    $parts = $pdo->query('PRAGMA main.index_xinfo("'.$index['name'].'")')->fetchAll(PDO::FETCH_ASSOC);
                    $parts = array_values(array_filter($parts, fn ($part) => (int) $part['key'] === 1));
                    foreach ($parts as $part) {
                        if ((int) $part['desc'] !== 0 || $part['coll'] !== 'BINARY' || (int) $part['cid'] < 0) {
                            $this->reject();
                        }
                    }
                    $actual[$index['name']] = array_column($parts, 'name');
                }
                ksort($actual);
                ksort($expected);
                if ($actual !== $expected) {
                    $this->reject();
                }
            }
        } else {
            foreach ($this->parentColumns() as $table => $columns) {
                if ($this->query($pdo, 'SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])
                    !== [['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => '', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']]) {
                    $this->reject();
                }
                $expected = [];
                foreach ($columns as $name => [$type, $nullable, $default, $extra]) {
                    $text = preg_match('/\A(?:varchar|char|text)/', $type) === 1;
                    $expected[] = ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable ? 'YES' : 'NO', 'COLUMN_DEFAULT' => $default,
                        'EXTRA' => $extra, 'CHARACTER_SET_NAME' => $text ? 'utf8mb4' : null, 'COLLATION_NAME' => $text ? 'utf8mb4_unicode_ci' : null,
                        'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
                }
                if ($this->query($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]) !== $expected) {
                    $this->reject();
                }
                $expected = [];
                $indexes = $this->parentIndexes($table);
                ksort($indexes);
                foreach ($indexes as $name => $columns) {
                    foreach ($columns as $offset => $column) {
                        $expected[] = ['INDEX_NAME' => $name, 'COLUMN_NAME' => $column, 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => $offset + 1,
                            'INDEX_TYPE' => 'BTREE', 'SUB_PART' => null, 'COLLATION' => 'A', 'EXPRESSION' => null, 'IS_VISIBLE' => 'YES', 'INDEX_COMMENT' => ''];
                    }
                }
                $actual = $this->query($pdo, 'SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]);
                usort($actual, fn ($left, $right) => strcmp($left['INDEX_NAME'], $right['INDEX_NAME']) ?: $left['SEQ_IN_INDEX'] <=> $right['SEQ_IN_INDEX']);
                if ($actual !== $expected) {
                    $this->reject();
                }
                $foreign = [];
                if ($table === 'customer_accounts') {
                    foreach (['owner_key' => ['quote_owners', 'owner_key'], 'user_id' => ['users', 'id']] as $column => [$target, $key]) {
                        $foreign[] = ['CONSTRAINT_NAME' => $table.'_'.$column.'_foreign', 'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $this->database($pdo),
                            'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => $key, 'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => 'RESTRICT'];
                    }
                }
                if ($this->query($pdo, 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME', [$table]) !== $foreign
                    || (int) $this->query($pdo, 'SELECT COUNT(*) total FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table])[0]['total'] !== count($indexes) + count($foreign)) {
                    $this->reject();
                }
            }
        }
        foreach ($guards as $name => $guard) {
            $expected = $driver === 'sqlite' ? [['sql' => $guard['sql']]] : [['EVENT_OBJECT_TABLE' => $guard['table'], 'EVENT_MANIPULATION' => $guard['event'], 'ACTION_TIMING' => $guard['timing'] ?? 'BEFORE', 'ACTION_STATEMENT' => $guard['body']]];
            $actual = $driver === 'sqlite' ? $this->query($pdo, "SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name=?", [$name])
                : $this->query($pdo, 'SELECT EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$name]);
            if ($actual !== $expected) {
                $this->reject();
            }
        }
        foreach (['users', 'customer_accounts', 'quote_owners'] as $table) {
            $actual = $driver === 'sqlite' ? $this->query($pdo, "SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name", [$table])
                : $this->query($pdo, 'SELECT TRIGGER_NAME name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table]);
            $names = array_keys(array_filter($guards, fn ($guard) => $guard['table'] === $table));
            sort($names);
            if ($actual !== array_map(fn ($name) => ['name' => $name], $names)) {
                $this->reject();
            }
        }
    }

    private function parentColumns(): array
    {
        $id = ['bigint unsigned', false, null, 'auto_increment'];
        $timestamp = ['timestamp', true, null, ''];
        $text = ['text', true, null, ''];

        return ['users' => ['id' => $id, 'name' => ['varchar(255)', false, null, ''], 'email' => ['varchar(255)', false, null, ''],
            'email_verified_at' => $timestamp, 'password' => ['varchar(255)', false, null, ''], 'remember_token' => ['varchar(100)', true, null, ''],
            'created_at' => $timestamp, 'updated_at' => $timestamp, 'is_admin' => ['tinyint(1)', false, '0', ''],
            'app_authentication_secret' => $text, 'app_authentication_recovery_codes' => $text],
            'customer_accounts' => ['id' => $id, 'public_id' => ['char(36)', false, null, ''], 'user_id' => ['bigint unsigned', false, null, ''],
                'owner_key' => ['char(64)', false, null, ''], 'active' => ['tinyint(1)', false, null, ''], 'access_version' => ['int unsigned', false, null, ''],
                'created_at' => $timestamp, 'updated_at' => $timestamp], 'quote_owners' => ['owner_key' => ['char(64)', false, null, '']]];
    }

    private function parentIndexes(string $table): array
    {
        return match ($table) {
            'users' => ['PRIMARY' => ['id'], 'users_email_unique' => ['email']],
            'customer_accounts' => ['PRIMARY' => ['id'], 'customer_accounts_public_id_unique' => ['public_id'],
                'customer_accounts_user_id_unique' => ['user_id'], 'customer_accounts_owner_key_unique' => ['owner_key']],
            'quote_owners' => ['PRIMARY' => ['owner_key']],
        };
    }

    private function parentGuards(string $driver): array
    {
        $sqlite = $driver === 'sqlite';
        $changed = $sqlite ? 'NEW.id IS NOT OLD.id OR NEW.public_id IS NOT OLD.public_id OR NEW.user_id IS NOT OLD.user_id OR NEW.owner_key IS NOT OLD.owner_key OR NEW.created_at IS NOT OLD.created_at'
            : 'NOT (NEW.id <=> OLD.id) OR NOT (BINARY NEW.public_id <=> BINARY OLD.public_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (BINARY NEW.owner_key <=> BINARY OLD.owner_key) OR NOT (NEW.created_at <=> OLD.created_at)';
        $shape = $sqlite ? "length(NEW.owner_key) != 64 OR NEW.owner_key GLOB '*[^0-9a-f]*' OR length(NEW.public_id) != 36 OR NEW.public_id GLOB '*[^0-9a-f-]*'"
            : "NOT REGEXP_LIKE(NEW.owner_key, '^[0-9a-f]{64}$', 'c') OR NOT REGEXP_LIKE(NEW.public_id, '^[0-9a-f-]{36}$', 'c')";
        $collision = 'EXISTS (SELECT 1 FROM customer_accounts WHERE id=NEW.id OR public_id=NEW.public_id OR user_id=NEW.user_id OR owner_key=NEW.owner_key)';
        $conditions = ['INSERT' => "$shape OR $collision OR NEW.active != 1 OR NEW.access_version != 1 OR NEW.created_at IS NULL OR NEW.updated_at IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.user_id AND is_admin=0 AND email_verified_at IS NOT NULL)",
            'UPDATE' => "$changed OR NEW.active NOT IN (0,1) OR NEW.active=OLD.active OR OLD.access_version >= 4294967294 OR NEW.access_version != OLD.access_version+1 OR NEW.updated_at IS NULL OR NEW.updated_at < OLD.updated_at", 'DELETE' => '1=1'];
        $guards = [];
        foreach ($conditions as $event => $condition) {
            $name = 'customer_accounts_'.strtolower($event);
            $body = "BEGIN IF $condition THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer identity is retained'; END IF; END";
            $guards[$name] = ['table' => 'customer_accounts', 'event' => $event, 'body' => $body,
                'sql' => "CREATE TRIGGER $name BEFORE $event ON customer_accounts WHEN $condition BEGIN SELECT RAISE(ABORT, 'Customer identity is retained'); END"];
        }
        foreach (['update', 'delete'] as $operation) {
            $name = 'quote_owners_immutable_'.$operation;
            $guards[$name] = ['table' => 'quote_owners', 'event' => strtoupper($operation),
                'body' => "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote evidence is immutable'",
                'sql' => "CREATE TRIGGER $name BEFORE $operation ON quote_owners BEGIN SELECT RAISE(ABORT, 'Quote evidence is immutable'); END"];
        }
        // The author branch predates PR25. Its reviewed users epoch guards are required in a composed install.
        if (class_exists(DiscoveryEpoch::class)) {
            foreach (DiscoveryEpoch::guards($driver) as $name => $guard) {
                if ($guard['table'] === 'users') {
                    $guards[$name] = $guard;
                }
            }
        }

        return $guards;
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
