<?php

namespace App\Domain\Catalog\Discovery;

use LogicException;
use PDO;

/** Additive transactional invalidation; existing domain writers keep their lock order. */
final class DiscoveryEpoch
{
    public const TABLE = 'catalog_discovery_epoch';

    public const MAX = 2147483647;

    public const DEPENDENCIES = ['users', 'tracks', 'rights_declarations', 'offers', 'offer_revisions',
        'license_templates', 'license_versions', 'license_review_evidence', 'media_assets', 'media_processing_runs',
        'stems_recordings', 'rights_scopes', 'rights_scope_offers', 'exclusive_activations', 'exclusive_sales',
        'inventory_reservations', 'inventory_claims'];

    public static function tableSql(string $driver): string
    {
        $tail = $driver === 'mysql' ? ' ENGINE=InnoDB' : '';

        return 'CREATE TABLE '.self::TABLE.' (id INTEGER NOT NULL PRIMARY KEY, epoch BIGINT NOT NULL, schema_version INTEGER NOT NULL, CONSTRAINT cde_id_check CHECK (id = 1), CONSTRAINT cde_epoch_check CHECK (epoch >= 0 AND epoch <= '.self::MAX.'), CONSTRAINT cde_schema_check CHECK (schema_version = 1))'.$tail;
    }

    public static function guards(string $driver): array
    {
        $guards = [];
        $table = self::TABLE;
        foreach (['insert', 'update', 'delete'] as $operation) {
            $allowed = match ($operation) {
                'insert' => "NEW.id = 1 AND NEW.epoch = 0 AND NEW.schema_version = 1 AND NOT EXISTS (SELECT 1 FROM {$table})",
                'update' => 'NEW.id = OLD.id AND NEW.schema_version = OLD.schema_version AND OLD.epoch < '.self::MAX.' AND (NEW.epoch = OLD.epoch + 1 OR NEW.epoch = OLD.epoch)',
                default => '0 = 1',
            };
            $name = 'cde_own_'.$operation;
            $body = $driver === 'sqlite' ? "SELECT RAISE(ABORT, 'Invalid discovery epoch');"
                : "IF NOT COALESCE(({$allowed}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Invalid discovery epoch'; END IF;";
            $sql = $driver === 'sqlite' ? "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} WHEN NOT COALESCE(({$allowed}), 0) BEGIN {$body} END"
                : "CREATE TRIGGER {$name} BEFORE {$operation} ON {$table} FOR EACH ROW BEGIN {$body} END";
            $guards[$name] = ['table' => $table, 'event' => strtoupper($operation), 'timing' => 'BEFORE', 'body' => 'BEGIN '.$body.' END', 'sql' => $sql];
        }
        foreach (self::DEPENDENCIES as $position => $dependency) {
            foreach (['insert', 'update', 'delete'] as $operation) {
                $name = 'cde_'.$position.'_'.$operation;
                $body = $driver === 'sqlite' ? "SELECT CASE WHEN (SELECT COUNT(*) FROM {$table} WHERE id = 1) <> 1 THEN RAISE(ABORT, 'Missing discovery epoch') END; UPDATE {$table} SET epoch = epoch + 1 WHERE id = 1;"
                    : "UPDATE {$table} SET epoch = epoch + 1 WHERE id = 1; IF ROW_COUNT() <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Missing discovery epoch'; END IF;";
                $sql = "CREATE TRIGGER {$name} AFTER {$operation} ON {$dependency}".($driver === 'mysql' ? ' FOR EACH ROW' : '')." BEGIN {$body} END";
                $guards[$name] = ['table' => $dependency, 'event' => strtoupper($operation), 'timing' => 'AFTER', 'body' => 'BEGIN '.$body.' END', 'sql' => $sql];
            }
        }

        return $guards;
    }

    public function assertInstalled(PDO $pdo, string $driver): void
    {
        $expected = self::guards($driver);
        if ($driver === 'sqlite') {
            $objects = $pdo->query('SELECT type, name, tbl_name, sql FROM sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $temporary = $pdo->query('SELECT name, tbl_name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($temporary as $object) {
                if (in_array(strtolower($object['name']), [self::TABLE, ...self::DEPENDENCIES, ...array_keys($expected)], true)
                    || strtolower($object['tbl_name']) === self::TABLE) {
                    throw new LogicException('Discovery schema is shadowed.');
                }
            }
            $byName = array_column($objects, null, 'name');
            if (($byName[self::TABLE]['type'] ?? null) !== 'table' || ($byName[self::TABLE]['sql'] ?? null) !== self::tableSql($driver)) {
                throw new LogicException('Discovery table ownership changed.');
            }
            foreach ($expected as $name => $guard) {
                if (($byName[$name]['type'] ?? null) !== 'trigger' || ($byName[$name]['tbl_name'] ?? null) !== $guard['table'] || ($byName[$name]['sql'] ?? null) !== $guard['sql']) {
                    throw new LogicException('Discovery guard ownership changed.');
                }
            }
            foreach ($objects as $object) {
                if ($object['tbl_name'] === self::TABLE && $object['name'] !== self::TABLE && ! isset($expected[$object['name']])) {
                    throw new LogicException('Unexpected discovery object.');
                }
            }
        } elseif ($driver === 'mysql') {
            $table = $pdo->query("SELECT TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".self::TABLE."'")->fetch(PDO::FETCH_ASSOC);
            if ($table !== ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB']) {
                throw new LogicException('Discovery table engine changed.');
            }
            $columns = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".self::TABLE."' ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_ASSOC);
            if ($columns !== [['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                ['COLUMN_NAME' => 'epoch', 'COLUMN_TYPE' => 'bigint', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => ''],
                ['COLUMN_NAME' => 'schema_version', 'COLUMN_TYPE' => 'int', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '']]) {
                throw new LogicException('Discovery table columns changed.');
            }
            $indexes = $pdo->query("SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".self::TABLE."'")->fetchAll(PDO::FETCH_ASSOC);
            if ($indexes !== [['INDEX_NAME' => 'PRIMARY', 'NON_UNIQUE' => 0, 'COLUMN_NAME' => 'id', 'SEQ_IN_INDEX' => 1]]) {
                throw new LogicException('Discovery indexes changed.');
            }
            $checks = $pdo->query("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = '".self::TABLE."'")->fetchAll(PDO::FETCH_ASSOC);
            $clauses = [];
            foreach ($checks as $check) {
                if ($check['ENFORCED'] !== 'YES') {
                    throw new LogicException('Discovery check enforcement changed.');
                }
                $clauses[] = preg_replace('/[`()\\s]+/', '', strtolower($check['CHECK_CLAUSE']));
            }
            sort($clauses);
            $wanted = ['id=1', 'epoch>=0andepoch<='.self::MAX, 'schema_version=1'];
            sort($wanted);
            if ($clauses !== $wanted) {
                throw new LogicException('Discovery checks changed.');
            }
            foreach (self::DEPENDENCIES as $dependency) {
                $statement = $pdo->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
                $statement->execute([$dependency]);
                if ($statement->fetchColumn() !== 'InnoDB') {
                    throw new LogicException('Discovery dependency engine changed.');
                }
                $definition = $pdo->query('SHOW CREATE TABLE '.$dependency)->fetch(PDO::FETCH_ASSOC);
                if (str_starts_with($definition['Create Table'] ?? '', 'CREATE TEMPORARY TABLE')) {
                    throw new LogicException('Discovery dependency is shadowed.');
                }
            }
            $constraints = $pdo->query("SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '".self::TABLE."' ORDER BY CONSTRAINT_NAME")->fetchAll(PDO::FETCH_ASSOC);
            if ($constraints !== [['CONSTRAINT_NAME' => 'cde_epoch_check', 'CONSTRAINT_TYPE' => 'CHECK'],
                ['CONSTRAINT_NAME' => 'cde_id_check', 'CONSTRAINT_TYPE' => 'CHECK'], ['CONSTRAINT_NAME' => 'cde_schema_check', 'CONSTRAINT_TYPE' => 'CHECK'],
                ['CONSTRAINT_NAME' => 'PRIMARY', 'CONSTRAINT_TYPE' => 'PRIMARY KEY']]) {
                throw new LogicException('Discovery constraints changed.');
            }
            $rows = $pdo->query('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
            $byName = array_column($rows, null, 'TRIGGER_NAME');
            foreach ($expected as $name => $guard) {
                $row = $byName[$name] ?? null;
                if (! $row || $row['EVENT_OBJECT_TABLE'] !== $guard['table'] || $row['EVENT_MANIPULATION'] !== $guard['event']
                    || $row['ACTION_TIMING'] !== $guard['timing'] || $row['ACTION_STATEMENT'] !== $guard['body']) {
                    throw new LogicException('Discovery guard ownership changed.');
                }
            }
            foreach ($rows as $row) {
                if ($row['EVENT_OBJECT_TABLE'] === self::TABLE && ! isset($expected[$row['TRIGGER_NAME']])) {
                    throw new LogicException('Unexpected discovery guard.');
                }
            }
            // SHOW resolves connection-local temporary shadows, unlike information_schema.
            $shown = $pdo->query('SHOW CREATE TABLE '.self::TABLE)->fetch(PDO::FETCH_ASSOC);
            if (str_starts_with($shown['Create Table'] ?? '', 'CREATE TEMPORARY TABLE')) {
                throw new LogicException('Discovery table is shadowed.');
            }
        } else {
            throw new LogicException('Unsupported discovery database.');
        }
    }

    public function current(PDO $pdo, bool $fence = false): int
    {
        $suffix = $fence && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $rows = $pdo->query('SELECT id, epoch, schema_version FROM '.self::TABLE.$suffix)->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== 1 || (int) $rows[0]['id'] !== 1 || (int) $rows[0]['schema_version'] !== 1
            || ! is_numeric($rows[0]['epoch']) || (int) $rows[0]['epoch'] < 0 || (int) $rows[0]['epoch'] >= self::MAX) {
            throw new LogicException('Discovery epoch is unavailable.');
        }

        return (int) $rows[0]['epoch'];
    }
}
