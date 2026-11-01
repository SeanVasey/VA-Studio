<?php

namespace App\Domain\Customers\ProductionIdentity;

use LogicException;
use PDO;
use PDOException;

/** Closed whole-account guard versions; no DDL and no per-trigger version alternatives. */
final class AccountAdmissionGuards
{
    public const V1 = 'v1';

    public const V2 = 'v2';

    public const OVERLAP = 'v1-v2-overlap';

    public const OLD_INSERT = 'customer_accounts_insert';

    public const NEW_INSERT = 'customer_accounts_insert_v2';

    /** Closed definitions preserve the historical migration output byte for byte. */
    public static function definitions(string $driver, string $version): array
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true) || ! in_array($version, [self::V1, self::V2, self::OVERLAP], true)
            || ($driver === 'sqlite' && $version !== self::V1)) {
            self::refuse();
        }
        $guards = self::v1($driver);
        if ($version !== self::V1) {
            $collision = 'EXISTS (SELECT 1 FROM customer_accounts WHERE id=NEW.id OR public_id=NEW.public_id OR user_id=NEW.user_id OR owner_key=NEW.owner_key)';
            $seeks = [];
            foreach (['id' => 'PRIMARY', 'public_id' => 'customer_accounts_public_id_unique', 'user_id' => 'customer_accounts_user_id_unique', 'owner_key' => 'customer_accounts_owner_key_unique'] as $column => $index) {
                $seeks[] = 'EXISTS (SELECT 1 FROM customer_accounts FORCE INDEX (`'.$index.'`) WHERE '.$column.'=NEW.'.$column.')';
            }
            $guard = $guards[self::OLD_INSERT];
            $body = str_replace($collision, '('.implode(' OR ', $seeks).')', $guard['body'], $count);
            if ($count !== 1) {
                self::refuse();
            }
            $guard['body'] = $body;
            $guard['sql'] = 'CREATE TRIGGER '.self::NEW_INSERT.' BEFORE INSERT ON customer_accounts FOR EACH ROW '.$body;
            if ($version === self::V2) {
                unset($guards[self::OLD_INSERT]);
            }
            $guards[self::NEW_INSERT] = $guard;
        }

        return $guards;
    }

    private static function v1(string $driver): array
    {
        $sqlite = $driver === 'sqlite';
        $changed = $sqlite
            ? 'NEW.id IS NOT OLD.id OR NEW.public_id IS NOT OLD.public_id OR NEW.user_id IS NOT OLD.user_id OR NEW.owner_key IS NOT OLD.owner_key OR NEW.created_at IS NOT OLD.created_at'
            : 'NOT (NEW.id <=> OLD.id) OR NOT (BINARY NEW.public_id <=> BINARY OLD.public_id) OR NOT (NEW.user_id <=> OLD.user_id) OR NOT (BINARY NEW.owner_key <=> BINARY OLD.owner_key) OR NOT (NEW.created_at <=> OLD.created_at)';
        $shape = $sqlite
            ? "length(NEW.owner_key) != 64 OR NEW.owner_key GLOB '*[^0-9a-f]*' OR length(NEW.public_id) != 36 OR NEW.public_id GLOB '*[^0-9a-f-]*'"
            : "NOT REGEXP_LIKE(NEW.owner_key, '^[0-9a-f]{64}$', 'c') OR NOT REGEXP_LIKE(NEW.public_id, '^[0-9a-f-]{36}$', 'c')";
        // SQLite REPLACE can suppress DELETE triggers. Refuse all conflicting identities before replacement.
        $collision = 'EXISTS (SELECT 1 FROM customer_accounts WHERE id=NEW.id OR public_id=NEW.public_id OR user_id=NEW.user_id OR owner_key=NEW.owner_key)';
        $conditions['INSERT'] = ("$shape OR $collision OR NEW.active != 1 OR NEW.access_version != 1 OR NEW.created_at IS NULL OR NEW.updated_at IS NULL OR NOT EXISTS (SELECT 1 FROM users WHERE id=NEW.user_id AND is_admin=0 AND email_verified_at IS NOT NULL)");
        $conditions['UPDATE'] = ("$changed OR NEW.active NOT IN (0,1) OR NEW.active=OLD.active OR OLD.access_version >= 4294967294 OR NEW.access_version != OLD.access_version+1 OR NEW.updated_at IS NULL OR NEW.updated_at < OLD.updated_at");
        $conditions['DELETE'] = ('1=1');
        $guards = [];
        foreach ($conditions as $operation => $when) {
            $name = 'customer_accounts_'.strtolower($operation);
            $body = "BEGIN IF $when THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Customer identity is retained'; END IF; END";
            $statement = $sqlite
                ? "CREATE TRIGGER $name BEFORE $operation ON customer_accounts WHEN $when BEGIN SELECT RAISE(ABORT, 'Customer identity is retained'); END"
                : "CREATE TRIGGER $name BEFORE $operation ON customer_accounts FOR EACH ROW $body";
            $guards[$name] = ['table' => 'customer_accounts', 'event' => $operation, 'timing' => 'BEFORE', 'body' => $body, 'sql' => $statement];
        }

        return $guards;
    }

    /** The only ordinary runtime admission: complete stable V1 or V2. */
    public static function stable(PDO $pdo, string $driver): array
    {
        return self::inspect($pdo, $driver, false);
    }

    /** Migration-only classifier additionally admits the one exact ordered overlap prefix. */
    public static function migrationState(PDO $pdo, string $driver): array
    {
        return self::inspect($pdo, $driver, true);
    }

    private static function inspect(PDO $pdo, string $driver, bool $transition): array
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true) || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== $driver) {
            self::refuse();
        }
        $names = [self::OLD_INSERT, self::NEW_INSERT, 'customer_accounts_update', 'customer_accounts_delete'];
        if ($driver === 'sqlite') {
            $objects = $pdo->query('SELECT name,type,tbl_name,sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($pdo->query('SELECT name,tbl_name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC) as $object) {
                if (in_array(strtolower($object['name']), ['customer_accounts', ...$names], true) || strtolower($object['tbl_name']) === 'customer_accounts') {
                    self::refuse();
                }
            }
            $table = array_values(array_filter($objects, fn ($row) => strtolower($row['name']) === 'customer_accounts'));
            if (count($table) !== 1 || $table[0]['name'] !== 'customer_accounts' || $table[0]['type'] !== 'table'
                || $table[0]['sql'] !== 'CREATE TABLE "customer_accounts" ("id" integer primary key autoincrement not null, "public_id" varchar not null, "user_id" integer not null, "owner_key" varchar not null, "active" tinyint(1) not null, "access_version" integer not null, "created_at" datetime, "updated_at" datetime, foreign key("user_id") references "users"("id") on delete restrict, foreign key("owner_key") references "quote_owners"("owner_key") on delete restrict)') {
                self::refuse();
            }
            $guards = self::definitions($driver, self::V1);
            $actual = [];
            foreach ($objects as $object) {
                if (in_array(strtolower($object['name']), $names, true) || ($object['type'] === 'trigger' && strtolower($object['tbl_name']) === 'customer_accounts')) {
                    $actual[$object['name']] = $object;
                }
            }
            $expected = [];
            foreach ($guards as $name => $guard) {
                $expected[$name] = ['name' => $name, 'type' => 'trigger', 'tbl_name' => 'customer_accounts', 'sql' => $guard['sql']];
            }
            ksort($actual);
            ksort($expected);
            if ($actual !== $expected) {
                self::refuse();
            }
            if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
                self::refuse();
            }
            $actualIndexes = [];
            foreach ($pdo->query('PRAGMA main.index_list("customer_accounts")')->fetchAll(PDO::FETCH_ASSOC) as $index) {
                if (! in_array($index['name'], ['customer_accounts_public_id_unique', 'customer_accounts_user_id_unique', 'customer_accounts_owner_key_unique'], true)
                    || (int) $index['unique'] !== 1 || (int) $index['partial'] !== 0) {
                    self::refuse();
                }
                $parts = $pdo->query('PRAGMA main.index_xinfo("'.$index['name'].'")')->fetchAll(PDO::FETCH_ASSOC);
                $keys = array_values(array_filter($parts, fn ($part) => (int) $part['key'] === 1));
                if (count($keys) !== 1 || (int) $keys[0]['desc'] !== 0 || $keys[0]['coll'] !== 'BINARY' || (int) $keys[0]['cid'] < 0) {
                    self::refuse();
                }
                $actualIndexes[$index['name']] = $keys[0]['name'];
            }
            ksort($actualIndexes);
            if ($actualIndexes !== ['customer_accounts_owner_key_unique' => 'owner_key', 'customer_accounts_public_id_unique' => 'public_id', 'customer_accounts_user_id_unique' => 'user_id']) {
                self::refuse();
            }
            $version = self::V1;
            $metadata = [$table, $actual];
        } else {
            $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
            if (! is_string($database) || $database === '') {
                self::refuse();
            }
            $table = self::query($pdo, 'SELECT TABLE_NAME,TABLE_TYPE,ENGINE,TABLE_COLLATION,TABLE_COMMENT,CREATE_OPTIONS FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['customer_accounts']);
            if ($table !== [['TABLE_NAME' => 'customer_accounts', 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_unicode_ci', 'TABLE_COMMENT' => '', 'CREATE_OPTIONS' => '']]) {
                self::refuse();
            }
            // Include both version namespaces even when the selected version does not use one name.
            foreach (['customer_accounts', ...$names] as $name) {
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE `'.str_replace('`', '``', $database).'`.`'.$name.'`')->fetch(PDO::FETCH_ASSOC);
                    if ($name !== 'customer_accounts' || str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE ')) {
                        self::refuse();
                    }
                } catch (PDOException $error) {
                    if ($name === 'customer_accounts' || ($error->errorInfo[1] ?? null) !== 1146) {
                        throw $error;
                    }
                }
            }
            $placeholders = implode(',', array_fill(0, count($names), '?'));
            if (self::query($pdo, 'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('.$placeholders.')', $names) !== []) {
                self::refuse();
            }
            $reserved = ['customer_accounts', ...$names];
            $placeholders = implode(',', array_fill(0, count($reserved), '?'));
            foreach ([['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME'], ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME']] as [$catalog, $schema, $column]) {
                if (self::query($pdo, "SELECT $column FROM information_schema.$catalog WHERE $schema=DATABASE() AND $column IN ($placeholders)", $reserved) !== []) {
                    self::refuse();
                }
            }
            $rows = self::query($pdo, 'SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,EVENT_MANIPULATION,ACTION_TIMING,ACTION_STATEMENT,ACTION_ORDER,SQL_MODE,CHARACTER_SET_CLIENT,COLLATION_CONNECTION,DATABASE_COLLATION,DEFINER FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND (EVENT_OBJECT_TABLE=? OR TRIGGER_NAME IN (?,?,?,?)) ORDER BY TRIGGER_NAME', ['customer_accounts', ...$names]);
            $installed = array_column($rows, 'TRIGGER_NAME');
            sort($installed);
            $version = null;
            foreach ($transition ? [self::V1, self::V2, self::OVERLAP] : [self::V1, self::V2] as $candidate) {
                $expectedNames = array_keys(self::definitions($driver, $candidate));
                sort($expectedNames);
                if ($installed === $expectedNames) {
                    $version = $candidate;
                    break;
                }
            }
            if ($version === null) {
                self::refuse();
            }
            $guards = self::definitions($driver, $version);
            $definer = null;
            foreach ($rows as $row) {
                $guard = $guards[$row['TRIGGER_NAME']];
                $order = $version === self::OVERLAP && $row['TRIGGER_NAME'] === self::NEW_INSERT ? 2 : 1;
                $modes = explode(',', $row['SQL_MODE']);
                sort($modes);
                $expectedModes = ['ERROR_FOR_DIVISION_BY_ZERO', 'NO_ENGINE_SUBSTITUTION', 'NO_ZERO_DATE', 'NO_ZERO_IN_DATE', 'ONLY_FULL_GROUP_BY', 'STRICT_TRANS_TABLES'];
                if ($row['EVENT_OBJECT_TABLE'] !== 'customer_accounts' || $row['EVENT_MANIPULATION'] !== $guard['event'] || $row['ACTION_TIMING'] !== 'BEFORE'
                    || $row['ACTION_STATEMENT'] !== $guard['body'] || (int) $row['ACTION_ORDER'] !== $order || $modes !== $expectedModes
                    || $row['CHARACTER_SET_CLIENT'] !== 'utf8mb4' || $row['COLLATION_CONNECTION'] !== 'utf8mb4_unicode_ci' || $row['DATABASE_COLLATION'] !== 'utf8mb4_unicode_ci'
                    || $row['DEFINER'] === '' || ($definer !== null && $row['DEFINER'] !== $definer)) {
                    self::refuse();
                }
                $definer = $row['DEFINER'];
            }
            // The forced equality paths must remain their complete, visible unique keys.
            $indexes = self::query($pdo, 'SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SEQ_IN_INDEX,INDEX_TYPE,SUB_PART,COLLATION,EXPRESSION,IS_VISIBLE,INDEX_COMMENT FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', ['customer_accounts']);
            usort($indexes, fn ($a, $b) => strcmp($a['INDEX_NAME'], $b['INDEX_NAME']) ?: $a['SEQ_IN_INDEX'] <=> $b['SEQ_IN_INDEX']);
            $expectedIndexes = [];
            foreach (['PRIMARY' => 'id', 'customer_accounts_owner_key_unique' => 'owner_key', 'customer_accounts_public_id_unique' => 'public_id', 'customer_accounts_user_id_unique' => 'user_id'] as $name => $column) {
                $expectedIndexes[] = ['INDEX_NAME' => $name, 'COLUMN_NAME' => $column, 'NON_UNIQUE' => 0, 'SEQ_IN_INDEX' => 1, 'INDEX_TYPE' => 'BTREE', 'SUB_PART' => null, 'COLLATION' => 'A', 'EXPRESSION' => null, 'IS_VISIBLE' => 'YES', 'INDEX_COMMENT' => ''];
            }
            if ($indexes !== $expectedIndexes) {
                self::refuse();
            }
            self::mysqlFloor($pdo, $database);
            $metadata = [$database, $table, $rows, $indexes];
        }

        return ['version' => $version, 'guards' => $guards, 'fingerprint' => hash('sha256', serialize([$version, $metadata])),
            'creation' => $driver === 'mysql' ? [$rows[0]['SQL_MODE'], $rows[0]['CHARACTER_SET_CLIENT'], $rows[0]['COLLATION_CONNECTION'], $rows[0]['DEFINER']] : null];
    }

    /** Complete retained account columns/outgoing keys, in addition to guard/index ownership. */
    private static function mysqlFloor(PDO $pdo, string $database): void
    {
        if ((int) $pdo->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn() !== 1) {
            self::refuse();
        }
        $expected = [];
        foreach (['id' => ['bigint unsigned', 'NO', 'auto_increment'], 'public_id' => ['char(36)', 'NO', ''],
            'user_id' => ['bigint unsigned', 'NO', ''], 'owner_key' => ['char(64)', 'NO', ''],
            'active' => ['tinyint(1)', 'NO', ''], 'access_version' => ['int unsigned', 'NO', ''],
            'created_at' => ['timestamp', 'YES', ''], 'updated_at' => ['timestamp', 'YES', '']] as $column => [$type, $nullable, $extra]) {
            $text = in_array($column, ['public_id', 'owner_key'], true);
            $expected[] = ['COLUMN_NAME' => $column, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable, 'COLUMN_DEFAULT' => null,
                'EXTRA' => $extra, 'CHARACTER_SET_NAME' => $text ? 'utf8mb4' : null, 'COLLATION_NAME' => $text ? 'utf8mb4_unicode_ci' : null,
                'COLUMN_COMMENT' => '', 'GENERATION_EXPRESSION' => ''];
        }
        if (self::query($pdo, 'SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,COLUMN_COMMENT,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION', ['customer_accounts']) !== $expected) {
            self::refuse();
        }
        $expected = [];
        foreach (['owner_key' => ['quote_owners', 'owner_key'], 'user_id' => ['users', 'id']] as $column => [$target, $key]) {
            $expected[] = ['CONSTRAINT_NAME' => 'customer_accounts_'.$column.'_foreign', 'COLUMN_NAME' => $column,
                'REFERENCED_TABLE_SCHEMA' => $database, 'REFERENCED_TABLE_NAME' => $target, 'REFERENCED_COLUMN_NAME' => $key,
                'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => 'RESTRICT'];
        }
        if (self::query($pdo, 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_SCHEMA,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.COLUMN_NAME', ['customer_accounts']) !== $expected
            || self::query($pdo, 'SELECT COUNT(*) count FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', ['customer_accounts']) !== [['count' => 6]]) {
            self::refuse();
        }
    }

    private static function query(PDO $pdo, string $sql, array $values): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute($values);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function refuse(): never
    {
        throw new LogicException('Exact retained account admission guard version required.');
    }
}
