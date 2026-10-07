<?php

namespace App\Domain\SupportAttachments;

use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;

/** Strict owned-prefix retry: atomic table DDL, then guards; never repair retained unguarded rows. */
final class AttachmentSchema
{
    private const IMMUTABLE = ['id', 'public_id', 'source_kind', 'source_id', 'source_family', 'source_binding', 'source_hash',
        'origin_hash', 'source_version', 'actor_hash', 'request_key', 'policy_binding', 'policy_hash', 'manifest', 'manifest_hash', 'bytes', 'expires_at', 'created_at'];

    public function assertOwned(): void
    {
        $this->up(false);
    }

    public function up(bool $allowDdl = true): void
    {
        $pdo = DB::connection()->getPdo();
        $driver = DB::getDriverName();
        $name = DB::connection()->getTablePrefix().'support_attachments';
        $this->require(in_array($driver, ['sqlite', 'mysql'], true) && strlen($name) <= 48 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1);
        $table = $driver === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
        $definitions = $this->columns($driver);
        $create = 'CREATE TABLE '.$table.' ('.implode(', ', array_map(fn ($column, $type) => '"'.$column.'" '.$type, array_keys($definitions), $definitions))
            .', CONSTRAINT "'.$name.'_public_unique" UNIQUE (public_id)'
            .', CONSTRAINT "'.$name.'_request_unique" UNIQUE (source_kind, source_id, actor_hash, request_key)'
            .', CONSTRAINT "'.$name.'_bounds" CHECK (bytes BETWEEN 1 AND 5242880 AND source_version >= 0 AND attempt BETWEEN 0 AND 3 AND expires_at > created_at'
            ." AND state IN ('receiving','quarantined','scanning','ready','failed','deleted','expired')))";
        if ($driver === 'mysql') {
            $create = str_replace('"', '`', $create).' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin';
        }
        $guards = $this->guards($driver, $name, $table);
        if ($driver === 'sqlite') {
            $temporary = $pdo->prepare('SELECT name FROM sqlite_temp_master WHERE name IN (?, ?, ?)');
            $temporary->execute([$name, ...array_keys($guards)]);
            $this->require($temporary->fetchAll() === []);
            $statement = $pdo->prepare('SELECT type, sql FROM main.sqlite_master WHERE name = ?');
            $statement->execute([$name]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                $this->require($existing['type'] === 'table' && $this->sql($existing['sql']) === $this->sql(str_replace('main.', '', $create)));
            }
            $indexes = $pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                $this->require(count($indexes) === 2 && count(array_filter($indexes, fn ($i) => $i['unique'] == 1)) === 2);
            }
        } else {
            $statement = $pdo->prepare('SELECT TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
            $statement->execute([$name]);
            $existing = $statement->fetch(PDO::FETCH_ASSOC);
            if ($existing !== false) {
                $this->require($existing === ['TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin']);
                $show = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM);
                $this->require(! str_contains(strtoupper($show[1]), 'TEMPORARY'));
                $this->mysqlTable($pdo, $name);
            }
        }
        $missing = [];
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
            $statement->execute([$name]);
            $this->require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === []);
        } else {
            $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ?');
            $statement->execute([$name]);
            $this->require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === []);
        }
        foreach ($guards as $guard => $definition) {
            if ($driver === 'sqlite') {
                $statement = $pdo->prepare('SELECT type, tbl_name, sql FROM main.sqlite_master WHERE name = ?');
                $statement->execute([$guard]);
                $actual = $statement->fetch(PDO::FETCH_ASSOC);
                if ($actual !== false) {
                    $this->require($actual['type'] === 'trigger' && $actual['tbl_name'] === $name && $this->sql($actual['sql']) === $this->sql($definition['sql']));
                }
            } else {
                $statement = $pdo->prepare('SELECT EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
                $statement->execute([$guard]);
                $actual = $statement->fetch(PDO::FETCH_ASSOC);
                if ($actual !== false) {
                    $this->require($actual['EVENT_OBJECT_TABLE'] === $name && $actual['EVENT_MANIPULATION'] === $definition['operation'] && $actual['ACTION_TIMING'] === 'BEFORE' && $this->sql($actual['ACTION_STATEMENT']) === $this->sql($definition['body']));
                }
            }
            if ($actual === false) {
                $missing[] = $definition['sql'];
            }
        }
        if ($existing !== false && $missing !== []) {
            $this->require($pdo->query('SELECT 1 FROM '.$table.' LIMIT 1')->fetchColumn() === false);
        }
        if (! $allowDdl) {
            $this->require($existing !== false && $missing === []);

            return;
        }
        if ($existing === false) {
            $this->require(count($missing) === count($guards));
            $pdo->exec($create);
        }
        foreach ($missing as $definition) {
            $pdo->exec($definition);
        }
    }

    public function down(): never
    {
        throw new LogicException('Attachment originals and tombstones require a separately approved retention migration.');
    }

    private function columns(string $driver): array
    {
        $ascii = $driver === 'mysql' ? ' CHARACTER SET ascii COLLATE ascii_bin' : ' COLLATE BINARY';
        $integer = $driver === 'mysql' ? 'BIGINT' : 'INTEGER';
        $text = $driver === 'mysql' ? 'LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' : 'TEXT';
        $result = ['id' => $driver === 'mysql' ? 'BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT'];
        foreach (['public_id' => 36, 'source_kind' => 40, 'source_id' => 36, 'source_family' => 64, 'source_hash' => 64, 'origin_hash' => 64, 'actor_hash' => 64, 'request_key' => 36, 'policy_hash' => 64, 'manifest_hash' => 64] as $name => $size) {
            $result[$name] = 'VARCHAR('.$size.')'.$ascii.' NOT NULL';
        }
        foreach (['source_binding', 'policy_binding', 'manifest'] as $name) {
            $result[$name] = $text.' NOT NULL';
        }
        foreach (['source_version', 'bytes', 'expires_at', 'attempt', 'created_at', 'updated_at'] as $name) {
            $result[$name] = $integer.' NOT NULL';
        }
        $result['state'] = 'VARCHAR(16)'.$ascii.' NOT NULL';
        $result['lease_token'] = 'VARCHAR(64)'.$ascii.' NULL';
        $result['lease_until'] = $integer.' NULL';
        $result['scan_evidence'] = $text.' NULL';
        $result['failure_code'] = 'VARCHAR(32)'.$ascii.' NULL';

        return $result;
    }

    private function guards(string $driver, string $name, string $table): array
    {
        $unchanged = implode(' AND ', array_map(fn ($column) => $driver === 'mysql' ? 'BINARY OLD.`'.$column.'` <=> BINARY NEW.`'.$column.'`' : 'OLD."'.$column.'" IS NEW."'.$column.'"', self::IMMUTABLE));
        $unchanged .= " AND OLD.state NOT IN ('deleted','expired') AND NEW.attempt >= OLD.attempt AND NEW.updated_at >= OLD.updated_at";
        $result = [];
        foreach (['update' => $unchanged, 'delete' => null] as $operation => $condition) {
            $guard = $name.'_'.$operation;
            if ($driver === 'sqlite') {
                $sql = 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($operation).' ON "'.$name.'"'.($condition === null ? '' : ' WHEN NOT ('.$condition.')')." BEGIN SELECT RAISE(ABORT, 'Attachment evidence is immutable'); END";
                $result[$guard] = ['sql' => $sql];
            } else {
                $body = "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Attachment evidence is immutable';";
                if ($condition !== null) {
                    $body = 'IF NOT ('.$condition.') THEN '.$body.' END IF;';
                }
                $body = 'BEGIN '.$body.' END';
                $result[$guard] = ['sql' => 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($operation).' ON '.$table.' FOR EACH ROW '.$body, 'body' => $body, 'operation' => strtoupper($operation)];
            }
        }

        return $result;
    }

    private function mysqlTable(PDO $pdo, string $name): void
    {
        $statement = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$name]);
        $columns = $statement->fetchAll(PDO::FETCH_ASSOC);
        $expected = $this->columns('mysql');
        $this->require(array_column($columns, 'COLUMN_NAME') === array_keys($expected));
        foreach ($columns as $column) {
            $definition = $expected[$column['COLUMN_NAME']];
            $type = strtolower(explode(' ', $definition)[0]);
            if ($column['COLUMN_NAME'] === 'id') {
                $type .= ' unsigned';
            }
            $this->require($column['COLUMN_TYPE'] === $type && $column['COLUMN_DEFAULT'] === null
                && $column['IS_NULLABLE'] === (str_contains($definition, 'NOT NULL') || $column['COLUMN_NAME'] === 'id' ? 'NO' : 'YES')
                && $column['EXTRA'] === ($column['COLUMN_NAME'] === 'id' ? 'auto_increment' : '')
                && $column['COLLATION_NAME'] === (str_contains($definition, 'ascii_bin') ? 'ascii_bin' : (str_contains($definition, 'utf8mb4_bin') ? 'utf8mb4_bin' : null)));
        }
        $indexes = $pdo->query('SHOW INDEX FROM `'.$name.'`')->fetchAll(PDO::FETCH_ASSOC);
        $actual = [];
        foreach ($indexes as $index) {
            $this->require($index['Non_unique'] == 0 && $index['Index_type'] === 'BTREE' && $index['Sub_part'] === null);
            $actual[$index['Key_name']][] = $index['Column_name'];
        }
        $wanted = ['PRIMARY' => ['id'], $name.'_public_unique' => ['public_id'], $name.'_request_unique' => ['source_kind', 'source_id', 'actor_hash', 'request_key']];
        ksort($actual);
        ksort($wanted);
        $this->require($actual === $wanted);
        $statement = $pdo->prepare('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $statement->execute([$name]);
        $constraints = $statement->fetchAll(PDO::FETCH_ASSOC);
        $this->require(count($constraints) === 4);
        $statement = $pdo->prepare('SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?');
        $statement->execute([$name.'_bounds']);
        $check = (string) $statement->fetchColumn();
        // MySQL canonicalizes BETWEEN/IN/parentheses. Pin the entire normalized expression, not a substring.
        $wantedCheck = "bytes between 1 and 5242880 and source_version >= 0 and attempt between 0 and 3 and expires_at > created_at and state in ('receiving','quarantined','scanning','ready','failed','deleted','expired')";
        $this->require($this->check($check) === $this->check($wantedCheck));
    }

    private function check(string $value): string
    {
        return strtolower(preg_replace('/[\s`()]+/', '', str_replace(["_utf8mb4\\'", "\\'"], ["'", "'"], $value)));
    }

    private function sql(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', $value), ' ;');
    }

    private function require(bool $condition): void
    {
        if (! $condition) {
            throw new LogicException('Attachment schema ownership or retained protection mismatch.');
        }
    }
}
