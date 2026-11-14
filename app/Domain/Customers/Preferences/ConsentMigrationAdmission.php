<?php

namespace App\Domain\Customers\Preferences;

use App\Domain\Catalog\Discovery\DiscoveryEpoch;
use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;
use App\Domain\Customers\ProductionIdentity\CatalogLease;
use App\Domain\Customers\ProductionIdentity\CatalogRead;
use LogicException;
use PDO;
use PDOException;

/** Read-only admission against the retained identity schema; never repairs/adopts dependencies. */
final class ConsentMigrationAdmission
{
    public function __construct(private PDO $pdo, private string $driver, private string $database) {}

    public function namespace(array $expected): void
    {
        $lease = CatalogLease::open($this->pdo);
        try {
            $this->namespaceCurrent($expected);
        } finally {
            $lease->close();
        }
    }

    private function namespaceCurrent(array $expected): void
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
                $rows = [...$this->query(CatalogRead::QA8762690E619, [$name]),
                    ...$this->query(CatalogRead::Q38A05D78DB1E, [$name])];
                if (count($rows) > 1 || ($rows !== [] && $rows[0] !== ['name' => $name, 'type' => $kind, 'tbl_name' => $owner])) {
                    $this->refuse();
                }
                foreach ([['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME'], ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME']] as [$dictionary, $schema, $column]) {
                    if ($this->query(CatalogRead::namespace($dictionary), [$name]) !== []) {
                        $this->refuse();
                    }
                }
            }
        }
    }

    public function dependencies(): void
    {
        $lease = CatalogLease::open($this->pdo);
        try {
            $this->dependenciesCurrent();
        } finally {
            $lease->close();
        }
    }

    private function dependenciesCurrent(): void
    {
        $this->inspectDependencies(AccountAdmissionGuards::stable($this->pdo, $this->driver)['guards']);
    }

    /** Migration-only exact overlap admission; ordinary dependencies() never takes this path. */
    public function dependenciesForAccountUpgrade(): void
    {
        $lease = CatalogLease::open($this->pdo);
        try {
            $this->dependenciesForAccountUpgradeCurrent();
        } finally {
            $lease->close();
        }
    }

    private function dependenciesForAccountUpgradeCurrent(): void
    {
        $this->inspectDependencies(AccountAdmissionGuards::migrationState($this->pdo, $this->driver)['guards']);
    }

    private function inspectDependencies(array $guards): void
    {
        if ((int) $this->pdo->query($this->driver === 'sqlite' ? 'PRAGMA foreign_keys' : 'SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
            $this->refuse();
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
                if ($this->query(CatalogRead::Q9D29C0ADA7EE, [$table]) !== [['ENGINE' => 'InnoDB', 'TABLE_TYPE' => 'BASE TABLE', 'TABLE_COMMENT' => '', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'CREATE_OPTIONS' => '']]) {
                    $this->refuse();
                }
                $expected = [];
                foreach ($columns as $column => [$type, $nullable, $default, $extra]) {
                    $text = preg_match('/\A(?:varchar|char|text)/', $type) === 1;
                    $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable ? 'YES' : 'NO', 'COLUMN_DEFAULT' => $default, 'EXTRA' => $extra,
                        'COLLATION_NAME' => $text ? 'utf8mb4_unicode_ci' : null, 'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
                }
                if ($this->query(CatalogRead::Q17E73B0D4F9F, [$table]) !== $expected) {
                    $this->refuse();
                }
                $this->mysqlIndexes($table);
                $this->mysqlForeign($table);
                $count = count($this->indexes($table)) + ($table === 'customer_accounts' ? 2 : 0);
                if ($this->query(CatalogRead::QB894B1A4D034, [$table]) !== [['count' => $count]]) {
                    $this->refuse();
                }
            }
        }
        foreach ($guards as $name => $guard) {
            $expected = $this->driver === 'sqlite' ? [['sql' => $guard['sql']]]
                : [['EVENT_OBJECT_TABLE' => $guard['table'], 'EVENT_MANIPULATION' => $guard['event'], 'ACTION_TIMING' => $guard['timing'], 'ACTION_STATEMENT' => $guard['body']]];
            $actual = $this->driver === 'sqlite' ? $this->query("SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name=?", [$name])
                : $this->query(CatalogRead::Q95556A0350AB, [$name]);
            if ($actual !== $expected) {
                $this->refuse();
            }
        }
        foreach (['users', 'customer_accounts', 'quote_owners'] as $table) {
            $rows = $this->driver === 'sqlite' ? $this->query("SELECT name FROM main.sqlite_master WHERE type='trigger' AND tbl_name=? ORDER BY name", [$table])
                : $this->query(CatalogRead::QB2684ECFE856, [$table]);
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
        $rows = $this->query(CatalogRead::Q6BD010449A99, [$table]);
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
        $rows = $this->query(CatalogRead::Q3A0D56156905, [$table]);
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

    private function query(CatalogRead|string $sql, array $values): array
    {
        if ($sql instanceof CatalogRead) {
            return CatalogLease::read($this->pdo, $sql, $values);
        }
        if ($this->driver !== 'sqlite') {
            $this->refuse();
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function refuse(): never
    {
        throw new LogicException('Consent namespace or retained identity dependencies require inspection; no DDL was attempted.');
    }
}
