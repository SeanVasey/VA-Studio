<?php

namespace App\Domain\Catalog\DiscoverySitemap;

use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Atomic table DDL and strict prefix recovery. No adoption or repair of retained unguarded rows. */
final class SitemapSchema
{
    public const TABLES = ['discovery_sitemap_generations', 'discovery_sitemap_windows', 'discovery_sitemap_current'];

    public function table(string $logical): string
    {
        SitemapException::require(in_array($logical, self::TABLES, true), 'schema');
        $name = DB::connection()->getTablePrefix().$logical;
        SitemapException::require(strlen($name) <= 48 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1, 'schema');

        return DB::getDriverName() === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
    }

    public function assertOwned(PDO $pdo): void
    {
        SitemapException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $this->up(false);
    }

    public function up(bool $ddl = true): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        SitemapException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        $this->assertNamespace($pdo, $driver);
        $plans = [];
        $absent = false;
        $prefixEnded = false;
        foreach (self::TABLES as $logical) {
            $table = $this->table($logical);
            $name = DB::connection()->getTablePrefix().$logical;
            $definition = $this->definition($logical, $driver);
            $create = 'CREATE TABLE '.$table.' ('.$definition['sql'].')'.($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '');
            $guards = $this->guards($logical, $driver, $name, $table);
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('SELECT name, tbl_name FROM sqlite_temp_master');
                $statement->execute();
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $object) {
                    SitemapException::require(! in_array(strtolower($object['name']), array_map(strtolower(...), [$name, ...array_keys($guards)]), true) && strtolower($object['tbl_name']) !== strtolower($name), 'shadowed_schema');
                }
                $statement = $pdo->prepare('SELECT name, type, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                SitemapException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    SitemapException::require($actual['type'] === 'table' && $this->sql($actual['sql']) === $this->sql(str_replace('main.', '', $create)), 'schema');
                    $indexes = $pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
                    SitemapException::require(count($indexes) === ($logical === self::TABLES[2] ? 0 : 1), 'schema');
                }
                $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
                $statement->execute([$name]);
                SitemapException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            } else {
                $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
                $statement->execute([$name]);
                $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                SitemapException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TABLE_NAME'] === $name), 'schema_namespace');
                $actual = $objects[0] ?? false;
                if ($actual !== false) {
                    unset($actual['TABLE_NAME']);
                }
                // SHOW also resolves a local temporary table when no durable table exists.
                try {
                    $shown = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                    SitemapException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'shadowed_schema');
                } catch (\PDOException $error) {
                    if ($actual !== false || $error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
                if ($actual !== false) {
                    SitemapException::require($actual === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin'], 'schema');
                    $this->mysqlDefinition($pdo, $name, $definition);
                }
                $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND EVENT_OBJECT_TABLE = ?');
                $statement->execute([$name]);
                SitemapException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'schema');
            }
            SitemapException::require(! (($absent || $prefixEnded) && $actual !== false), 'schema_prefix');
            $absent = $absent || $actual === false;
            $prefixEnded = $prefixEnded || $actual === false;
            $missing = [];
            foreach ($guards as $guard => $expected) {
                if ($driver === 'sqlite') {
                    $statement = $pdo->prepare('SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    SitemapException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['name'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        SitemapException::require($row['type'] === 'trigger' && $row['tbl_name'] === $name && $this->sql($row['sql']) === $this->sql($expected['sql']), 'schema');
                    }
                } else {
                    $statement = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)');
                    $statement->execute([$guard]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    SitemapException::require(count($objects) <= 1 && ($objects === [] || $objects[0]['TRIGGER_NAME'] === $guard), 'schema_namespace');
                    $row = $objects[0] ?? false;
                    if ($row !== false) {
                        SitemapException::require($row['EVENT_OBJECT_TABLE'] === $name && $row['EVENT_MANIPULATION'] === $expected['event'] && $row['ACTION_TIMING'] === 'BEFORE' && $this->sql($row['ACTION_STATEMENT']) === $this->sql($expected['body']), 'schema');
                    }
                }
                SitemapException::require(! ($prefixEnded && $row !== false), 'schema_prefix');
                $prefixEnded = $prefixEnded || $row === false;
                if ($row === false) {
                    $missing[] = $expected['sql'];
                }
            }
            if ($actual !== false && $missing !== []) {
                SitemapException::require($pdo->query('SELECT 1 FROM '.$table.' LIMIT 1')->fetchColumn() === false, 'retained_unguarded_schema');
            }
            SitemapException::require($ddl || ($actual !== false && $missing === []), 'schema');
            SitemapException::require($actual !== false || count($missing) === count($guards), 'schema');
            $plans[] = ['create' => $actual === false ? $create : null, 'guards' => $missing];
        }
        // Inspect the entire existing graph before the first write, including later drift/foreign names.
        if ($ddl) {
            foreach ($plans as $plan) {
                if ($plan['create'] !== null) {
                    $pdo->exec($plan['create']);
                }
                foreach ($plan['guards'] as $sql) {
                    $pdo->exec($sql);
                }
            }
            $this->up(false);
            $current = $this->table(self::TABLES[2]);
            if ($pdo->query('SELECT 1 FROM '.$current.' LIMIT 1')->fetchColumn() === false) {
                $pdo->exec("INSERT INTO {$current} (id, revision, generation_id, certificate, seal) VALUES (1, 0, NULL, NULL, '".str_repeat('0', 64)."')");
            }
        }
    }

    public function down(): never
    {
        throw new LogicException('Discovery sitemap generation evidence requires an explicit retention migration.');
    }

    private function definition(string $logical, string $driver): array
    {
        $name = DB::connection()->getTablePrefix().$logical;
        $ascii = $driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ' COLLATE BINARY';
        $text = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'TEXT';
        $int = $driver === 'mysql' ? 'BIGINT' : 'INTEGER';
        $id = 'VARCHAR(32)'.$ascii.' NOT NULL';
        $hash = 'VARCHAR(64)'.$ascii.' NOT NULL';
        if ($logical === self::TABLES[0]) {
            $columns = ['id' => $id, 'header' => $text.' NOT NULL', 'seal' => $hash];
            $primary = ['id'];
            $check = 'length(id) = 32 AND length(seal) = 64';
            $foreign = [];
        } elseif ($logical === self::TABLES[1]) {
            $columns = ['generation_id' => $id, 'ordinal' => 'INTEGER NOT NULL', 'after_id' => $int.' NOT NULL', 'end_id' => $int.' NOT NULL', 'more' => 'INTEGER NOT NULL', 'prior_hash' => $hash, 'body' => $text.' NOT NULL', 'seal' => $hash];
            $primary = ['generation_id', 'ordinal'];
            $check = 'ordinal BETWEEN 1 AND 128 AND after_id >= 0 AND end_id >= after_id AND more IN (0,1) AND length(seal) = 64 AND length(prior_hash) = 64';
            $foreign = ['generation_id' => self::TABLES[0]];
        } else {
            $columns = ['id' => 'INTEGER NOT NULL', 'revision' => $int.' NOT NULL', 'generation_id' => 'VARCHAR(32)'.$ascii.' NULL', 'certificate' => $text.' NULL', 'seal' => $hash];
            $primary = ['id'];
            $check = 'id = 1 AND revision BETWEEN 0 AND 2147483647 AND length(seal) = 64';
            $foreign = ['generation_id' => self::TABLES[0]];
        }
        $quote = $driver === 'mysql' ? '`' : '"';
        $sql = implode(', ', array_map(fn ($column, $type) => $quote.$column.$quote.' '.$type, array_keys($columns), $columns));
        $sql .= ', PRIMARY KEY ('.implode(', ', $primary).'), CONSTRAINT '.$quote.$name.'_bounds'.$quote.' CHECK ('.$check.')';
        foreach ($foreign as $column => $target) {
            $sql .= ', CONSTRAINT '.$quote.$name.'_owner'.$quote.' FOREIGN KEY ('.$column.') REFERENCES '.$quote.DB::connection()->getTablePrefix().$target.$quote.' (id) ON UPDATE RESTRICT ON DELETE RESTRICT';
        }

        return compact('sql', 'columns', 'primary', 'check', 'foreign');
    }

    private function guards(string $logical, string $driver, string $name, string $table): array
    {
        $generation = $this->table(self::TABLES[0]);
        $windows = $this->table(self::TABLES[1]);
        if ($logical === self::TABLES[0]) {
            $insert = "NOT EXISTS (SELECT 1 FROM {$table} WHERE id = NEW.id)";
            $update = '0 = 1';
        } elseif ($logical === self::TABLES[1]) {
            $insert = "NOT EXISTS (SELECT 1 FROM {$table} WHERE generation_id = NEW.generation_id AND ordinal = NEW.ordinal) AND EXISTS (SELECT 1 FROM {$generation} WHERE id = NEW.generation_id) AND ((NEW.ordinal = 1 AND NEW.after_id = 0 AND NEW.prior_hash = '".str_repeat('0', 64)."') OR EXISTS (SELECT 1 FROM {$windows} WHERE generation_id = NEW.generation_id AND ordinal = NEW.ordinal - 1 AND more = 1 AND end_id = NEW.after_id AND seal = NEW.prior_hash))";
            $update = '0 = 1';
        } else {
            $insert = "NEW.id = 1 AND NEW.revision = 0 AND NEW.generation_id IS NULL AND NEW.certificate IS NULL AND NEW.seal = '".str_repeat('0', 64)."' AND NOT EXISTS (SELECT 1 FROM {$table})";
            $update = "NEW.id = OLD.id AND OLD.revision < 2147483647 AND NEW.revision = OLD.revision + 1 AND NEW.generation_id IS NOT NULL AND NEW.certificate IS NOT NULL AND EXISTS (SELECT 1 FROM {$generation} WHERE id = NEW.generation_id)";
        }
        $result = [];
        foreach (['insert' => $insert, 'update' => $update, 'delete' => '0 = 1'] as $event => $condition) {
            $guard = $name.'_'.$event;
            // SQLite trigger body references must be unqualified; they resolve the guarded main tables.
            $condition = str_replace('main.', '', $condition);
            $body = $driver === 'sqlite' ? "BEGIN SELECT RAISE(ABORT, 'Immutable discovery sitemap evidence'); END"
                : "BEGIN IF NOT COALESCE(({$condition}), 0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Immutable discovery sitemap evidence'; END IF; END";
            $sql = $driver === 'sqlite' ? 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($event).' ON "'.$name.'" WHEN NOT COALESCE(('.$condition.'), 0) '.$body
                : 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($event).' ON '.$table.' FOR EACH ROW '.$body;
            $result[$guard] = ['sql' => $sql, 'body' => $body, 'event' => strtoupper($event)];
        }

        return $result;
    }

    private function assertNamespace(PDO $pdo, string $driver): void
    {
        $reserved = [];
        foreach (self::TABLES as $logical) {
            $name = DB::connection()->getTablePrefix().$logical;
            $this->table($logical);
            $reserved[strtolower($name)] = ['name' => $name, 'type' => 'table'];
            foreach (['insert', 'update', 'delete'] as $event) {
                $guard = $name.'_'.$event;
                $reserved[strtolower($guard)] = ['name' => $guard, 'type' => 'trigger'];
            }
        }
        if ($driver === 'sqlite') {
            // Inline non-integer primary keys create schema-global autoindexes. Their
            // identity belongs to the owned table, unlike SQLite's named CHECK/FK clauses.
            $indexes = [];
            foreach (array_slice(self::TABLES, 0, 2) as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                $indexes[strtolower('sqlite_autoindex_'.$name.'_1')] = ['name' => 'sqlite_autoindex_'.$name.'_1', 'type' => 'index', 'tbl_name' => $name, 'sql' => null];
            }
            $objects = $pdo->query('SELECT name, type, tbl_name, sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            $seen = [];
            foreach ($objects as $object) {
                $key = strtolower($object['name']);
                if (isset($indexes[$key])) {
                    SitemapException::require(! isset($seen[$key]) && $indexes[$key] === $object, 'schema_namespace');
                    $seen[$key] = true;
                }
                $rows[] = ['name' => $object['name'], 'type' => $object['type']];
            }
        } else {
            // CHECK and FK symbols are schema-global in distinct native namespaces.
            // Query with the dictionary's name collation, then require exact bytes and
            // ownership. A foreign table-local index/unique name is allowed to coexist.
            $statement = $pdo->prepare('SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?');
            foreach (self::TABLES as $logical) {
                $name = DB::connection()->getTablePrefix().$logical;
                $definition = $this->definition($logical, $driver);
                $constraints = [$name.'_bounds' => 'CHECK'];
                if ($definition['foreign'] !== []) {
                    $constraints[$name.'_owner'] = 'FOREIGN KEY';
                }
                foreach ($constraints as $symbol => $type) {
                    $statement->execute([$type, $symbol]);
                    $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
                    SitemapException::require($objects === [] || $objects === [['CONSTRAINT_NAME' => $symbol, 'TABLE_NAME' => $name, 'CONSTRAINT_TYPE' => $type]], 'schema_namespace');
                }
            }
            $rows = $pdo->query("SELECT TABLE_NAME AS name, 'table' AS type FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() UNION ALL SELECT TRIGGER_NAME AS name, 'trigger' AS type FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE()")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($reserved as $expected) {
                if ($expected['type'] === 'trigger') {
                    try {
                        $pdo->query('SHOW CREATE TABLE `'.$expected['name'].'`')->fetchAll();
                        throw new SitemapException('schema_namespace');
                    } catch (\PDOException $error) {
                        if ($error->getCode() !== '42S02') {
                            throw $error;
                        }
                    }
                }
            }
        }
        $seen = [];
        foreach ($rows as $row) {
            $key = strtolower($row['name']);
            if (isset($reserved[$key])) {
                SitemapException::require(! isset($seen[$key]) && $reserved[$key] === $row, 'schema_namespace');
                $seen[$key] = true;
            }
        }
    }

    private function mysqlDefinition(PDO $pdo, string $name, array $definition): void
    {
        $s = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $s->execute([$name]);
        $columns = $s->fetchAll(PDO::FETCH_ASSOC);
        SitemapException::require(array_column($columns, 'COLUMN_NAME') === array_keys($definition['columns']), 'schema');
        foreach ($columns as $column) {
            $expected = $definition['columns'][$column['COLUMN_NAME']];
            SitemapException::require($column['COLUMN_TYPE'] === (str_starts_with($expected, 'INTEGER') ? 'int' : strtolower(explode(' ', $expected)[0])) && $column['COLUMN_DEFAULT'] === null && $column['EXTRA'] === ''
                && $column['IS_NULLABLE'] === (str_contains($expected, 'NOT NULL') ? 'NO' : 'YES')
                && $column['COLLATION_NAME'] === (str_contains($expected, 'ascii_bin') ? 'ascii_bin' : (str_contains($expected, 'utf8mb4_bin') ? 'utf8mb4_bin' : null)), 'schema');
        }
        $indexes = $pdo->query('SHOW INDEX FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($indexes as $index) {
            SitemapException::require($index['Index_type'] === 'BTREE' && $index['Sub_part'] === null && $index['Non_unique'] == ($index['Key_name'] === 'PRIMARY' ? 0 : 1), 'schema');
            $actual[$index['Key_name']][] = $index['Column_name'];
        }
        $wanted = ['PRIMARY' => $definition['primary']];
        if ($name === DB::connection()->getTablePrefix().self::TABLES[2]) {
            $wanted[$name.'_owner'] = ['generation_id'];
        }
        ksort($actual);
        ksort($wanted);
        SitemapException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ?');
        $s->execute([$name]);
        $actual = array_column($s->fetchAll(PDO::FETCH_ASSOC), 'CONSTRAINT_TYPE', 'CONSTRAINT_NAME');
        $wanted = ['PRIMARY' => 'PRIMARY KEY', $name.'_bounds' => 'CHECK'];
        foreach ($definition['foreign'] as $column => $target) {
            $wanted[$name.'_owner'] = 'FOREIGN KEY';
            $s = $pdo->prepare('SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_owner']);
            SitemapException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['COLUMN_NAME' => $column, 'REFERENCED_TABLE_NAME' => DB::connection()->getTablePrefix().$target, 'REFERENCED_COLUMN_NAME' => 'id', 'ORDINAL_POSITION' => 1]], 'schema');
            $s = $pdo->prepare('SELECT UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND BINARY TABLE_NAME = BINARY ? AND CONSTRAINT_NAME = ?');
            $s->execute([$name, $name.'_owner']);
            SitemapException::require($s->fetchAll(PDO::FETCH_ASSOC) === [['UPDATE_RULE' => 'RESTRICT', 'DELETE_RULE' => 'RESTRICT']], 'schema');
        }
        ksort($actual);
        ksort($wanted);
        SitemapException::require($actual === $wanted, 'schema');
        $s = $pdo->prepare("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON BINARY t.CONSTRAINT_SCHEMA = BINARY c.CONSTRAINT_SCHEMA AND BINARY t.CONSTRAINT_NAME = BINARY c.CONSTRAINT_NAME WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE() AND BINARY t.TABLE_NAME = BINARY ? AND t.CONSTRAINT_TYPE = 'CHECK' AND t.CONSTRAINT_NAME = ?");
        $s->execute([$name, $name.'_bounds']);
        $rows = $s->fetchAll(PDO::FETCH_ASSOC);
        SitemapException::require(count($rows) === 1 && $rows[0]['ENFORCED'] === 'YES' && $this->check($rows[0]['CHECK_CLAUSE']) === $this->check($definition['check']), 'schema');
    }

    private function sql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql), ' ;');
    }

    private function check(string $sql): string
    {
        return strtolower(preg_replace('/[\s`()]+/', '', $sql));
    }
}
