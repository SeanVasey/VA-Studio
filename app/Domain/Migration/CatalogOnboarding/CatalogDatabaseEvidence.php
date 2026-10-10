<?php

declare(strict_types=1);

namespace App\Domain\Migration\CatalogOnboarding;

use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Current private SQLite or MySQL (InnoDB) reads bypass QueryExecuted callbacks, including storage-class identity.
 *
 * The SQLite branch is the original contract and is unchanged. The MySQL branch proves the same
 * guarantees with information_schema and InnoDB: the live owned schema is compared with the rows the
 * reviewed migration grammar produces, session enforcement switches must be on, and every evidence read
 * is a locking read, so no other session can insert into or update a read table until the importer's
 * transaction ends. Any other driver is refused.
 */
final class CatalogDatabaseEvidence
{
    private const TABLES = ['users', 'tracks', 'audit_events', 'catalog_import_batches', 'catalog_import_mappings',
        'media_assets', 'rights_declarations', 'offers', 'offer_revisions'];

    private const IMMUTABLE = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Catalog import evidence is immutable'";

    /** The pinned owned schema is actual live SQL, not a migrations-history claim. */
    public function schema(): string
    {
        $pdo = DB::connection()->getPdo();
        if (DB::getDriverName() === 'mysql') {
            return $this->mysqlSchema($pdo);
        }
        if (DB::getDriverName() !== 'sqlite' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite'
            || $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1
            || $pdo->query('PRAGMA writable_schema')->fetchColumn() !== 0
            || $pdo->query('PRAGMA ignore_check_constraints')->fetchColumn() !== 0) {
            throw new RuntimeException('catalog_target_schema_invalid');
        }
        $expected = $this->expectedSchema();
        $statement = $pdo->query("SELECT name, type, sql FROM sqlite_master WHERE tbl_name IN ('catalog_import_batches', 'catalog_import_mappings') ORDER BY name");
        $current = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($current !== $expected) {
            throw new RuntimeException('catalog_target_schema_invalid');
        }

        return hash('sha256', serialize($current));
    }

    private function expectedSchema(): array
    {
        $commonId = '"id" integer primary key autoincrement not null';
        $created = '"created_at" datetime not null default CURRENT_TIMESTAMP';
        $definitions = [
            'catalog_import_batches' => [$commonId, '"review_sha256" varchar not null', '"source_sha256" varchar not null',
                '"source_identity_sha256" varchar not null', '"target_commit" varchar not null', '"target_schema_sha256" varchar not null',
                '"transform_version" varchar not null', '"actor_id" integer not null', '"review_ciphertext" text not null', $created,
                'foreign key("actor_id") references "users"("id") on delete restrict'],
            'catalog_import_mappings' => [$commonId, '"batch_id" integer not null', '"record_key" varchar not null',
                '"source_record_sha256" varchar not null', '"track_id" integer not null', '"track_sha256" varchar not null',
                '"actor_id" integer not null', '"evidence_ciphertext" text not null', $created,
                'foreign key("batch_id") references "catalog_import_batches"("id") on delete restrict',
                'foreign key("track_id") references "tracks"("id") on delete restrict',
                'foreign key("actor_id") references "users"("id") on delete restrict'],
        ];
        $rows = [];
        foreach ($definitions as $table => $definition) {
            $rows[] = ['name' => $table, 'type' => 'table', 'sql' => 'CREATE TABLE "'.$table.'" ('.implode(', ', $definition).')'];
            $unique = $table === 'catalog_import_batches' ? ['review_sha256'] : ['record_key', 'track_id'];
            foreach ($unique as $column) {
                $name = $table.'_'.$column.'_unique';
                $rows[] = ['name' => $name, 'type' => 'index',
                    'sql' => 'CREATE UNIQUE INDEX "'.$name.'" on "'.$table.'" ("'.$column.'")'];
            }
            foreach (['update', 'delete'] as $operation) {
                $name = $table.'_immutable_'.$operation;
                $rows[] = ['name' => $name, 'type' => 'trigger',
                    'sql' => 'CREATE TRIGGER '.$name.' BEFORE '.strtoupper($operation).' ON '.$table." BEGIN SELECT RAISE(ABORT, 'Catalog import evidence is immutable'); END"];
            }
            $name = $table.'_immutable_replace';
            $conditions = array_map(static fn (string $column): string => '"'.$column.'" = NEW."'.$column.'"', ['id', ...$unique]);
            $rows[] = ['name' => $name, 'type' => 'trigger',
                'sql' => 'CREATE TRIGGER '.$name.' BEFORE INSERT ON '.$table.' WHEN EXISTS (SELECT 1 FROM '.$table.' WHERE '.implode(' OR ', $conditions).") BEGIN SELECT RAISE(ABORT, 'Catalog import evidence is immutable'); END"];
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

        return $rows;
    }

    /**
     * InnoDB admission: the session must enforce foreign keys, uniqueness and strict storage, run
     * REPEATABLE READ with autocommit (so the importer's own transaction boundary is the only one), fetch
     * native types, and address the configured database. The owned objects are then compared exactly.
     */
    private function mysqlSchema(PDO $pdo): string
    {
        $connection = DB::connection();
        $charset = $connection->getConfig('charset');
        $collation = $connection->getConfig('collation');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql' || $pdo->getAttribute(PDO::ATTR_STRINGIFY_FETCHES) !== false
            || ! is_string($charset) || $charset === '' || ! is_string($collation) || $collation === '') {
            throw new RuntimeException('catalog_target_schema_invalid');
        }
        $session = $pdo->query('SELECT @@session.foreign_key_checks, @@session.unique_checks, @@session.autocommit, '
            .'@@session.sql_mode, @@session.transaction_isolation, DATABASE()')->fetch(PDO::FETCH_NUM);
        $modes = is_array($session) && is_string($session[3]) ? explode(',', $session[3]) : [];
        if (! is_array($session) || $session[0] !== 1 || $session[1] !== 1 || $session[2] !== 1
            || (! in_array('STRICT_TRANS_TABLES', $modes, true) && ! in_array('STRICT_ALL_TABLES', $modes, true))
            || $session[4] !== 'REPEATABLE-READ' || $session[5] !== $connection->getDatabaseName()) {
            throw new RuntimeException('catalog_target_schema_invalid');
        }
        $current = $this->mysqlObjects($pdo);
        if ($current !== $this->expectedMysqlObjects($collation, $session[5])) {
            throw new RuntimeException('catalog_target_schema_invalid');
        }

        return hash('sha256', serialize($current));
    }

    /** The live owned objects from information_schema, sorted in PHP so server collation cannot reorder them. */
    private function mysqlObjects(PDO $pdo): array
    {
        $owned = "('catalog_import_batches', 'catalog_import_mappings')";
        $objects = [
            'tables' => ['SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES'
                ." WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $owned", ['TABLE_NAME']],
            'columns' => ['SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLUMN_KEY, COLLATION_NAME'
                ." FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $owned", ['TABLE_NAME', 'ORDINAL_POSITION']],
            'indexes' => ['SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS'
                ." WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN $owned", ['TABLE_NAME', 'INDEX_NAME', 'SEQ_IN_INDEX']],
            // The referenced schema is read too: an owned foreign key repointed at another schema's same-named table is drift.
            'foreign_keys' => ['SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME,'
                .' r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS r JOIN information_schema.KEY_COLUMN_USAGE k'
                .' ON k.CONSTRAINT_SCHEMA = r.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = r.CONSTRAINT_NAME AND k.TABLE_NAME = r.TABLE_NAME'
                ." WHERE r.CONSTRAINT_SCHEMA = DATABASE() AND r.TABLE_NAME IN $owned", ['TABLE_NAME', 'CONSTRAINT_NAME', 'ORDINAL_POSITION']],
            'checks' => ['SELECT t.TABLE_NAME, c.CONSTRAINT_NAME, c.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS c'
                .' JOIN information_schema.TABLE_CONSTRAINTS t ON t.CONSTRAINT_SCHEMA = c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME = c.CONSTRAINT_NAME'
                ." WHERE c.CONSTRAINT_SCHEMA = DATABASE() AND t.TABLE_NAME IN $owned", ['TABLE_NAME', 'CONSTRAINT_NAME']],
            'triggers' => ['SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER, ACTION_TIMING, ACTION_ORIENTATION, ACTION_STATEMENT'
                ." FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN $owned", ['TRIGGER_NAME']],
        ];
        $current = [];
        foreach ($objects as $kind => [$sql, $order]) {
            $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 200) {
                throw new RuntimeException('catalog_target_schema_invalid');
            }
            usort($rows, static function (array $left, array $right) use ($order): int {
                foreach ($order as $key) {
                    $result = is_int($left[$key]) && is_int($right[$key]) ? $left[$key] <=> $right[$key] : strcmp((string) $left[$key], (string) $right[$key]);
                    if ($result !== 0) {
                        return $result;
                    }
                }

                return 0;
            });
            $current[$kind] = $rows;
        }

        return $current;
    }

    /** The rows the reviewed migration produces on InnoDB for the connection's configured collation and database. */
    private function expectedMysqlObjects(string $collation, string $database): array
    {
        $tables = ['catalog_import_batches' => [
            'columns' => [['review_sha256', 'char(64)', 'UNI'], ['source_sha256', 'char(64)', ''], ['source_identity_sha256', 'char(64)', ''],
                ['target_commit', 'char(40)', ''], ['target_schema_sha256', 'char(64)', ''], ['transform_version', 'varchar(80)', ''],
                ['actor_id', 'bigint unsigned', 'MUL'], ['review_ciphertext', 'longtext', '']],
            'unique' => ['review_sha256'],
            'foreign_keys' => [['actor_id', 'users', 'catalog_import_batches_actor_id_foreign', true]],
        ], 'catalog_import_mappings' => [
            'columns' => [['batch_id', 'bigint unsigned', 'MUL'], ['record_key', 'char(64)', 'UNI'], ['source_record_sha256', 'char(64)', ''],
                ['track_id', 'bigint unsigned', 'UNI'], ['track_sha256', 'char(64)', ''], ['actor_id', 'bigint unsigned', 'MUL'],
                ['evidence_ciphertext', 'longtext', '']],
            'unique' => ['record_key', 'track_id'],
            // The track foreign key reuses the unique index, so it adds no index of its own.
            'foreign_keys' => [['batch_id', 'catalog_import_batches', 'catalog_import_mappings_batch_id_foreign', true],
                ['track_id', 'tracks', 'catalog_import_mappings_track_id_foreign', false],
                ['actor_id', 'users', 'catalog_import_mappings_actor_id_foreign', true]],
        ]];
        $expected = ['tables' => [], 'columns' => [], 'indexes' => [], 'foreign_keys' => [], 'checks' => [], 'triggers' => []];
        foreach ($tables as $table => $definition) {
            $expected['tables'][] = ['TABLE_NAME' => $table, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => $collation];
            $columns = [['id', 'bigint unsigned', 'PRI'], ...$definition['columns'], ['created_at', 'timestamp', '']];
            foreach ($columns as $position => [$name, $type, $key]) {
                $textual = in_array($type, ['char(64)', 'char(40)', 'varchar(80)', 'longtext'], true);
                $expected['columns'][] = ['TABLE_NAME' => $table, 'ORDINAL_POSITION' => $position + 1, 'COLUMN_NAME' => $name,
                    'COLUMN_TYPE' => $type, 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => $name === 'created_at' ? 'CURRENT_TIMESTAMP' : null,
                    'EXTRA' => match ($name) {
                        'id' => 'auto_increment', 'created_at' => 'DEFAULT_GENERATED', default => '',
                    }, 'COLUMN_KEY' => $key, 'COLLATION_NAME' => $textual ? $collation : null];
            }
            $indexes = [['PRIMARY', 0, 'id']];
            foreach ($definition['unique'] as $column) {
                $indexes[] = [$table.'_'.$column.'_unique', 0, $column];
            }
            foreach ($definition['foreign_keys'] as [$column, $referenced, $name, $ownIndex]) {
                if ($ownIndex) {
                    $indexes[] = [$name, 1, $column];
                }
                $expected['foreign_keys'][] = ['TABLE_NAME' => $table, 'CONSTRAINT_NAME' => $name, 'ORDINAL_POSITION' => 1,
                    'COLUMN_NAME' => $column, 'REFERENCED_TABLE_SCHEMA' => $database, 'REFERENCED_TABLE_NAME' => $referenced, 'REFERENCED_COLUMN_NAME' => 'id',
                    'UPDATE_RULE' => 'NO ACTION', 'DELETE_RULE' => 'RESTRICT'];
            }
            foreach ($indexes as [$name, $nonUnique, $column]) {
                $expected['indexes'][] = ['TABLE_NAME' => $table, 'INDEX_NAME' => $name, 'NON_UNIQUE' => $nonUnique, 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => $column];
            }
            foreach (['update', 'delete'] as $operation) {
                $expected['triggers'][] = ['TRIGGER_NAME' => $table.'_immutable_'.$operation, 'EVENT_MANIPULATION' => strtoupper($operation),
                    'EVENT_OBJECT_TABLE' => $table, 'ACTION_ORDER' => 1, 'ACTION_TIMING' => 'BEFORE', 'ACTION_ORIENTATION' => 'ROW',
                    'ACTION_STATEMENT' => self::IMMUTABLE];
            }
        }
        foreach (['indexes' => ['TABLE_NAME', 'INDEX_NAME'], 'foreign_keys' => ['TABLE_NAME', 'CONSTRAINT_NAME'], 'triggers' => ['TRIGGER_NAME']] as $kind => $order) {
            usort($expected[$kind], static fn (array $left, array $right): int => strcmp(implode("\0", array_intersect_key($left, array_flip($order))),
                implode("\0", array_intersect_key($right, array_flip($order)))));
        }

        return $expected;
    }

    public function rows(string $table): array
    {
        if (DB::getDriverName() === 'mysql') {
            return $this->mysqlRows($table);
        }
        if (DB::getDriverName() !== 'sqlite' || ! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('catalog_target_invalid');
        }
        $pdo = DB::connection()->getPdo();
        $columns = $pdo->query('PRAGMA table_info("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC);
        if ($columns === [] || count($columns) > 100) {
            throw new RuntimeException('catalog_target_invalid');
        }
        $select = [];
        foreach ($columns as $index => $column) {
            $name = '"'.str_replace('"', '""', $column['name']).'"';
            $select[] = $name;
            $select[] = 'typeof('.$name.') AS "__catalog_type_'.$index.'"';
        }
        $statement = $pdo->query('SELECT '.implode(', ', $select).' FROM "'.$table.'" ORDER BY "id"');
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (count($rows) >= 100000) {
                throw new RuntimeException('catalog_target_invalid');
            }
            $types = [];
            foreach ($columns as $index => $column) {
                $types[$column['name']] = $row['__catalog_type_'.$index];
                unset($row['__catalog_type_'.$index]);
            }
            if (! is_int($row['id']) || $row['id'] < 1) {
                throw new RuntimeException('catalog_target_invalid');
            }
            $rows[$row['id']] = ['attributes' => $row, 'types' => $types,
                'sha256' => hash('sha256', serialize([$row, $types]))];
        }

        return $rows;
    }

    /**
     * A locking read (FOR SHARE): inside the importer's transaction its next-key locks hold every read row
     * and gap until commit, which is the InnoDB equivalent of SQLite's single writer. Strict mode fixes the
     * stored type, so the declared DATA_TYPE (or null) is the row's storage class.
     */
    private function mysqlRows(string $table): array
    {
        if (! in_array($table, self::TABLES, true)) {
            throw new RuntimeException('catalog_target_invalid');
        }
        $pdo = DB::connection()->getPdo();
        $statement = $pdo->prepare('SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$table]);
        $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($columns === [] || count($columns) > 100) {
            throw new RuntimeException('catalog_target_invalid');
        }
        $select = [];
        $dataTypes = [];
        foreach ($columns as $column) {
            $select[] = '`'.str_replace('`', '``', $column['COLUMN_NAME']).'`';
            $dataTypes[$column['COLUMN_NAME']] = $column['DATA_TYPE'];
        }
        $statement = $pdo->query('SELECT '.implode(', ', $select).' FROM `'.$table.'` ORDER BY `id` FOR SHARE');
        $rows = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            if (count($rows) >= 100000 || count($row) !== count($dataTypes)) {
                throw new RuntimeException('catalog_target_invalid');
            }
            $types = [];
            foreach ($row as $name => $value) {
                $types[$name] = $value === null ? 'null' : $dataTypes[$name];
            }
            if (! is_int($row['id']) || $row['id'] < 1) {
                throw new RuntimeException('catalog_target_invalid');
            }
            $rows[$row['id']] = ['attributes' => $row, 'types' => $types,
                'sha256' => hash('sha256', serialize([$row, $types]))];
        }

        return $rows;
    }

    public function target(array $excludeTracks = [], array $excludeMappings = [], array $excludeAudits = []): array
    {
        $snapshot = [];
        foreach (['tracks' => $excludeTracks, 'catalog_import_mappings' => $excludeMappings, 'audit_events' => $excludeAudits] as $table => $excluded) {
            $rows = $this->rows($table);
            foreach ($excluded as $id) {
                unset($rows[$id]);
            }
            $snapshot[$table] = array_map(static fn (array $row): array => ['id' => $row['attributes']['id'], 'sha256' => $row['sha256']], array_values($rows));
        }

        return $snapshot;
    }

    public function childless(int $trackId): bool
    {
        foreach (['media_assets', 'rights_declarations', 'offers', 'offer_revisions'] as $table) {
            foreach ($this->rows($table) as $row) {
                if ($row['attributes']['track_id'] === $trackId) {
                    return false;
                }
            }
        }

        return true;
    }
}
