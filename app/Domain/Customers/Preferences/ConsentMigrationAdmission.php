<?php

namespace App\Domain\Customers\Preferences;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use LogicException;
use PDO;
use PDOException;
use ReflectionMethod;

/** Read-only admission against the retained identity schema; never repairs/adopts dependencies. */
final class ConsentMigrationAdmission
{
    public function __construct(private PDO $pdo, private string $driver, private string $database) {}

    public function namespace(array $expected): void
    {
        foreach ($expected as $name => [$kind, $owner]) {
            $this->shadow($name);
            if ($this->driver === 'sqlite') {
                $rows = $this->query('SELECT name,type,tbl_name FROM main.sqlite_master WHERE name COLLATE NOCASE=?', [$name]);
                if (count($rows) > 1 || ($rows !== [] && $rows[0] !== ['name' => $name, 'type' => $kind, 'tbl_name' => $owner])) {
                    $this->refuse();
                }
            } else {
                // Parameter comparison uses each actual dictionary column's native collation.
                // PHP lowercasing / LOWER(column) cannot prove accent/case aliases or object kind.
                $rows = [...$this->query("SELECT TABLE_NAME name,'table' type,TABLE_NAME tbl_name FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?", [$name]),
                    ...$this->query("SELECT TRIGGER_NAME name,'trigger' type,EVENT_OBJECT_TABLE tbl_name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?", [$name])];
                if (count($rows) > 1 || ($rows !== [] && $rows[0] !== ['name' => $name, 'type' => $kind, 'tbl_name' => $owner])) {
                    $this->refuse();
                }
                foreach ([['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME'], ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME']] as [$dictionary, $schema, $column]) {
                    if ($this->query("SELECT $column FROM information_schema.$dictionary WHERE $schema=DATABASE() AND $column=?", [$name]) !== []) {
                        $this->refuse();
                    }
                }
            }
        }
    }

    public function dependencies(): void
    {
        if ((int) $this->pdo->query($this->driver === 'sqlite' ? 'PRAGMA foreign_keys' : 'SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
            $this->refuse();
        }
        $migration = require database_path('migrations/2026_10_06_000040_customer_accounts.php');
        // Reuse the unchanged authoritative guard definitions without running up/down or DDL.
        $guards = [];
        foreach ((new ReflectionMethod($migration, 'guards'))->invoke($migration) as $name => $guard) {
            $guards[$name] = ['table' => 'customer_accounts', 'event' => $guard['operation'], 'timing' => 'BEFORE', 'body' => $guard['body'], 'sql' => $guard['statement']];
        }
        foreach (DiscoveryEpoch::guards($this->driver) as $name => $guard) {
            if ($guard['table'] === 'users') {
                $guards[$name] = $guard;
            }
        }
        foreach (['update', 'delete'] as $operation) {
            $name = 'quote_owners_immutable_'.$operation;
            $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Quote evidence is immutable'";
            $guards[$name] = ['table' => 'quote_owners', 'event' => strtoupper($operation), 'timing' => 'BEFORE', 'body' => $body,
                'sql' => "CREATE TRIGGER $name BEFORE $operation ON quote_owners BEGIN SELECT RAISE(ABORT, 'Quote evidence is immutable'); END"];
        }
        $namespace = [];
        foreach (['users', 'quote_owners', 'customer_accounts'] as $table) {
            $namespace[$table] = ['table', $table];
        }
        foreach ($guards as $name => $guard) {
            $namespace[$name] = ['trigger', $guard['table']];
        }
        $this->namespace($namespace);
        if ($this->driver === 'sqlite') {
            $definitions = [
                'users' => 'CREATE TABLE "users" ("id" integer primary key autoincrement not null, "name" varchar not null, "email" varchar not null, "email_verified_at" datetime, "password" varchar not null, "remember_token" varchar, "created_at" datetime, "updated_at" datetime, "is_admin" tinyint(1) not null default \'0\', "app_authentication_secret" text, "app_authentication_recovery_codes" text)',
                'customer_accounts' => 'CREATE TABLE "customer_accounts" ("id" integer primary key autoincrement not null, "public_id" varchar not null, "user_id" integer not null, "owner_key" varchar not null, "active" tinyint(1) not null, "access_version" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete restrict, foreign key("owner_key") references "quote_owners"("owner_key") on delete restrict)',
                'quote_owners' => 'CREATE TABLE "quote_owners" ("owner_key" varchar not null, primary key ("owner_key"))',
            ];
            foreach ($definitions as $table => $sql) {
                if ($this->query("SELECT sql FROM main.sqlite_master WHERE name=? AND type='table'", [$table]) !== [['sql' => $sql]]) {
                    $this->refuse();
                }
                $this->sqliteIndexes($table);
            }
        } else {
            foreach ($this->columns() as $table => $columns) {
                if ($this->query('SELECT ENGINE,TABLE_TYPE,TABLE_COMMENT,TABLE_COLLATION,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]) !== [['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => '', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']]) {
                    $this->refuse();
                }
                $expected = [];
                foreach ($columns as $column => [$type, $nullable, $default, $extra]) {
                    $text = preg_match('/\A(?:varchar|char|text)/', $type) === 1;
                    $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable ? 'YES' : 'NO', 'COLUMN_DEFAULT' => $default, 'EXTRA' => $extra,
                        'COLLATION_NAME' => $text ? 'utf8mb4_unicode_ci' : null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
                }
                if ($this->query('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', [$table]) !== $expected) {
                    $this->refuse();
                }
                $this->mysqlIndexes($table);
                $this->mysqlForeign($table);
                $count = count($this->indexes($table)) + ($table === 'customer_accounts' ? 2 : 0);
                if ($this->query('SELECT COUNT(*) count FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]) !== [['count' => $count]]) {
                    $this->refuse();
                }
            }
        }
        foreach ($guards as $name => $guard) {
            $expected = $this->driver === 'sqlite' ? [['sql' => $guard['sql']]]
                : [['EVENT_OBJECT_TABLE' => $guard['table'], 'EVENT_MANIPULATION' => $guard['event'], 'ACTION_TIMING' => $guard['timing'], 'ACTION_STATEMENT' => $guard['body']]];
            $actual = $this->driver === 'sqlite' ? $this->query("SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name=?", [$name])
                : $this->query('SELECT EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$name]);
            if ($actual !== $expected) {
                $this->refuse();
            }
        }
        foreach (['users', 'customer_accounts', 'quote_owners'] as $table) {
            $rows = $this->driver === 'sqlite' ? $this->query("SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name", [$table])
                : $this->query('SELECT TRIGGER_NAME name FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND EVENT_OBJECT_TABLE=? ORDER BY TRIGGER_NAME', [$table]);
            $names = array_keys(array_filter($guards, fn ($guard) => $guard['table'] === $table));
            sort($names);
            if ($rows !== array_map(fn ($name) => ['name' => $name], $names)) {
                $this->refuse();
            }
        }
    }

    private function columns(): array
    {
        $id = ['bigint unsigned', false, null, 'auto_increment'];
        $timestamp = ['timestamp', true, null, ''];
        $text = ['text', true, null, ''];

        return [
            'users' => ['id' => $id, 'name' => ['varchar(255)', false, null, ''], 'email' => ['varchar(255)', false, null, ''], 'email_verified_at' => $timestamp,
                'password' => ['varchar(255)', false, null, ''], 'remember_token' => ['varchar(100)', true, null, ''], 'created_at' => $timestamp, 'updated_at' => $timestamp,
                'is_admin' => ['tinyint(1)', false, '0', ''], 'app_authentication_secret' => $text, 'app_authentication_recovery_codes' => $text],
            'customer_accounts' => ['id' => $id, 'public_id' => ['char(36)', false, null, ''], 'user_id' => ['bigint unsigned', false, null, ''], 'owner_key' => ['char(64)', false, null, ''],
                'active' => ['tinyint(1)', false, null, ''], 'access_version' => ['int unsigned', false, null, ''], 'created_at' => $timestamp, 'updated_at' => $timestamp],
            'quote_owners' => ['owner_key' => ['char(64)', false, null, '']],
        ];
    }

    private function indexes(string $table): array
    {
        return match ($table) {
            'users' => ['PRIMARY' => ['id'], 'users_email_unique' => ['email']],
            'customer_accounts' => ['PRIMARY' => ['id'], 'customer_accounts_public_id_unique' => ['public_id'], 'customer_accounts_user_id_unique' => ['user_id'], 'customer_accounts_owner_key_unique' => ['owner_key']],
            'quote_owners' => ['PRIMARY' => ['owner_key']],
        };
    }

    private function sqliteIndexes(string $table): void
    {
        $expected = $this->indexes($table);
        unset($expected['PRIMARY']);
        if ($table === 'quote_owners') {
            $expected = ['sqlite_autoindex_quote_owners_1' => ['owner_key']];
        }
        $actual = [];
        foreach ($this->pdo->query('PRAGMA main.index_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
            if (! isset($expected[$index['name']]) || (int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) {
                $this->refuse();
            }
            $columns = $this->pdo->query('PRAGMA main.index_xinfo("'.$index['name'].'")')->fetchAll(PDO::FETCH_ASSOC);
            $keyColumns = array_values(array_filter($columns, fn ($column) => (int) $column['key'] === 1));
            foreach ($keyColumns as $column) {
                if ((int) $column['desc'] !== 0 || $column['coll'] !== 'BINARY' || (int) $column['cid'] < 0) {
                    $this->refuse();
                }
            }
            $actual[$index['name']] = array_map(fn ($column) => $column['name'], $keyColumns);
        }
        ksort($actual);
        ksort($expected);
        if ($actual !== $expected) {
            $this->refuse();
        }
    }

    private function mysqlIndexes(string $table): void
    {
        $rows = $this->query('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]);
        usort($rows, fn ($left, $right) => strcmp($left['INDEX_NAME'], $right['INDEX_NAME']) ?: $left['SEQ_IN_INDEX'] <=> $right['SEQ_IN_INDEX']);
        $expected = $this->indexes($table);
        ksort($expected);
        $keys = [];
        foreach ($expected as $name => $columns) {
            foreach ($columns as $offset => $column) {
                $keys[] = ['INDEX_NAME' => $name, 'COLUMN_NAME' => $column, 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => $offset + 1, 'INDEX_TYPE' => 'BTREE', 'SUB_PART' => null, 'COLLATION' => 'A', 'EXPRESSION' => null, 'IS_VISIBLE' => 'YES'];
            }
        }
        if ($rows !== $keys) {
            $this->refuse();
        }
    }

    private function mysqlForeign(string $table): void
    {
        $rows = $this->query('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME', [$table]);
        $expected = [];
        if ($table === 'customer_accounts') {
            foreach (['owner_key' => ['quote_owners', 'owner_key'], 'user_id' => ['users', 'id']] as $column => [$target, $key]) {
                $expected[] = ['CONSTRAINT_NAME' => $table.'_'.$column.'_foreign', 'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $this->database, 'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => $key, 'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => 'RESTRICT'];
            }
        }
        if ($rows !== $expected) {
            $this->refuse();
        }
    }

    private function shadow(string $name): void
    {
        if ($this->driver === 'sqlite') {
            if ($this->query('SELECT name FROM sqlite_temp_master WHERE name COLLATE NOCASE=? OR tbl_name COLLATE NOCASE=?', [$name, $name]) !== []) {
                $this->refuse();
            }

            return;
        }
        try {
            $row = $this->pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $this->database).'`.`'.$name.'`')->fetch(PDO::FETCH_ASSOC);
            if (str_contains(strtoupper((string) ($row['Create Table'] ?? '')), 'CREATE TEMPORARY TABLE')) {
                $this->refuse();
            }
        } catch (PDOException $error) {
            if (($error->errorInfo[0] ?? null) !== '42S02' || ($error->errorInfo[1] ?? null) !== 1146) {
                throw $error;
            }
        }
    }

    private function query(string $sql, array $values): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function refuse(): never
    {
        throw new LogicException('Consent namespace or retained identity dependencies require inspection; no DDL was attempted.');
    }
}
