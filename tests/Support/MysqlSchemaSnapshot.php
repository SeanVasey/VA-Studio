<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use PDO;
use Throwable;

/**
 * Reuses one migrated MySQL schema across the tests of a process instead of rebuilding it for every test.
 *
 * A full `migrate:fresh` on MySQL takes about 85 s (Foundation run 43304362814; profiled on 2026-11-13): several installers
 * re-verify their whole installation through information_schema before every DDL step, which is intended production
 * behaviour. Tests only need the state the migrations leave behind, so the first test of a process migrates and records:
 *
 * - a fingerprint of every schema object (tables, columns, indexes, foreign keys, checks, triggers, routines, views);
 * - the rows the migrations themselves wrote, and each table's next AUTO_INCREMENT value.
 *
 * Later tests verify the fingerprint and reset only what changed: TRUNCATE (which fires no triggers) on every table that
 * holds rows or whose counter moved, then re-insert the recorded rows. Any difference in the schema, and any error while
 * resetting, makes the caller run the full `migrate:fresh` as before, so a reset can cost speed but never correctness.
 * VA_TEST_MIGRATE_EACH_TEST=1 turns the reuse off.
 */
final class MysqlSchemaSnapshot
{
    /** @var array{identity: string, fingerprint: string, rows: array<string, array{columns: list<string>, rows: list<array>}>, increments: array<string, int>}|null */
    private static ?array $baseline = null;

    public static function enabled(Connection $connection): bool
    {
        return $connection->getDriverName() === 'mysql' && getenv('VA_TEST_MIGRATE_EACH_TEST') !== '1';
    }

    /** Resets the database to the recorded post-migration state; false (and the baseline forgotten) when it cannot. */
    public static function restore(Connection $connection): bool
    {
        if (self::$baseline === null || ! self::enabled($connection) || self::$baseline['identity'] !== self::identity($connection)) {
            self::$baseline = null;

            return false;
        }
        $pdo = $connection->getPdo();
        try {
            if (! hash_equals(self::$baseline['fingerprint'], self::fingerprint($pdo))) {
                self::$baseline = null;

                return false;
            }
            $pdo->exec('SET SESSION information_schema_stats_expiry = 0');
            $tables = self::baseTables($pdo);
            $increments = self::increments($pdo);
            $occupied = self::occupied($pdo, $tables);
            $reset = [];
            foreach ($tables as $table) {
                if (isset($occupied[$table]) || isset(self::$baseline['rows'][$table])
                    || ($increments[$table] ?? null) !== (self::$baseline['increments'][$table] ?? null)) {
                    $reset[] = $table;
                }
            }
            $pdo->exec('SET SESSION foreign_key_checks = 0');
            try {
                foreach ($reset as $table) {
                    $pdo->exec('TRUNCATE TABLE '.self::quote($table));
                }
                foreach (self::$baseline['rows'] as $table => ['columns' => $columns, 'rows' => $rows]) {
                    self::insert($pdo, $table, $columns, $rows);
                }
            } finally {
                $pdo->exec('SET SESSION foreign_key_checks = 1');
            }
            foreach ($reset as $table) {
                $expected = self::$baseline['increments'][$table] ?? null;
                if ($expected !== null && (self::increments($pdo, $table)[$table] ?? null) !== $expected) {
                    $pdo->exec('ALTER TABLE '.self::quote($table).' AUTO_INCREMENT = '.$expected);
                }
            }

            return true;
        } catch (Throwable $exception) {
            self::$baseline = null;
            fwrite(STDERR, 'MysqlSchemaSnapshot: reset refused ('.get_class($exception).': '.substr($exception->getMessage(), 0, 240).'); migrating fresh.'.PHP_EOL);

            return false;
        }
    }

    /** Records the state a successful migrate:fresh left behind. */
    public static function capture(Connection $connection): void
    {
        self::$baseline = null;
        if (! self::enabled($connection)) {
            return;
        }
        $pdo = $connection->getPdo();
        $pdo->exec('SET SESSION information_schema_stats_expiry = 0');
        $tables = self::baseTables($pdo);
        $generated = [];
        foreach ($pdo->query('SELECT TABLE_NAME, COLUMN_NAME, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION')->fetchAll(PDO::FETCH_NUM) as [$table, $column, $extra]) {
            $generated[$table][$column] = stripos((string) $extra, 'GENERATED') !== false;
        }
        $rows = [];
        foreach (array_keys(self::occupied($pdo, $tables)) as $table) {
            $columns = array_keys(array_filter($generated[$table] ?? [], fn (bool $isGenerated): bool => ! $isGenerated));
            $list = implode(', ', array_map(self::quote(...), $columns));
            $order = in_array('id', $columns, true) ? ' ORDER BY `id`' : '';
            $rows[$table] = ['columns' => $columns, 'rows' => $pdo->query("SELECT {$list} FROM ".self::quote($table).$order)->fetchAll(PDO::FETCH_NUM)];
        }
        self::$baseline = ['identity' => self::identity($connection), 'fingerprint' => self::fingerprint($pdo), 'rows' => $rows,
            'increments' => self::increments($pdo)];
    }

    public static function forget(): void
    {
        self::$baseline = null;
    }

    private static function identity(Connection $connection): string
    {
        return json_encode(array_intersect_key($connection->getConfig(), array_flip(['driver', 'host', 'port', 'database', 'unix_socket', 'prefix'])), JSON_THROW_ON_ERROR);
    }

    /** Every schema object the migrations own, canonically ordered; counters and statistics excluded. */
    private static function fingerprint(PDO $pdo): string
    {
        $queries = [
            'SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
            'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_DEFAULT, IS_NULLABLE, COLUMN_TYPE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY, EXTRA, GENERATION_EXPRESSION, COLUMN_COMMENT, SRS_ID FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION',
            'SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, SUB_PART, COLLATION, INDEX_TYPE, IS_VISIBLE, EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            'SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE',
            'SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, ORDINAL_POSITION, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION',
            'SELECT CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE, TABLE_NAME, REFERENCED_TABLE_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY TABLE_NAME, CONSTRAINT_NAME',
            'SELECT CONSTRAINT_NAME, CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() ORDER BY CONSTRAINT_NAME',
            'SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER, ACTION_TIMING, ACTION_STATEMENT, SQL_MODE FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY EVENT_OBJECT_TABLE, TRIGGER_NAME',
            'SELECT ROUTINE_NAME, ROUTINE_TYPE, DATA_TYPE, ROUTINE_DEFINITION, IS_DETERMINISTIC, SQL_DATA_ACCESS, SECURITY_TYPE, SQL_MODE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE() ORDER BY ROUTINE_TYPE, ROUTINE_NAME',
            'SELECT TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, SECURITY_TYPE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME',
            'SELECT EVENT_NAME, EVENT_DEFINITION, STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE() ORDER BY EVENT_NAME',
        ];
        $context = hash_init('sha256');
        foreach ($queries as $query) {
            hash_update($context, json_encode($pdo->query($query)->fetchAll(PDO::FETCH_NUM), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL);
        }

        return hash_final($context);
    }

    /** @return list<string> */
    private static function baseTables(PDO $pdo): array
    {
        return $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")
            ->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<string, int> next AUTO_INCREMENT value of each table that has one (stats expiry 0: read from the engine) */
    private static function increments(PDO $pdo, ?string $only = null): array
    {
        $statement = $pdo->prepare("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND AUTO_INCREMENT IS NOT NULL".($only === null ? '' : ' AND TABLE_NAME = ?'));
        $statement->execute($only === null ? [] : [$only]);
        $increments = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$table, $next]) {
            $increments[$table] = (int) $next;
        }

        return $increments;
    }

    /** @return array<string, true> tables holding at least one row */
    private static function occupied(PDO $pdo, array $tables): array
    {
        $occupied = [];
        foreach (array_chunk($tables, 200) as $chunk) {
            $probes = array_map(fn (string $table): string => 'SELECT '.$pdo->quote($table).' FROM '.self::quote($table).' HAVING COUNT(*) > 0', $chunk);
            foreach ($pdo->query(implode(' UNION ALL ', $probes))->fetchAll(PDO::FETCH_COLUMN) as $table) {
                $occupied[$table] = true;
            }
        }

        return $occupied;
    }

    private static function insert(PDO $pdo, string $table, array $columns, array $rows): void
    {
        $list = implode(', ', array_map(self::quote(...), $columns));
        $row = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        foreach (array_chunk($rows, max(1, intdiv(10_000, max(1, count($columns))))) as $chunk) {
            $statement = $pdo->prepare('INSERT INTO '.self::quote($table)." ({$list}) VALUES ".implode(', ', array_fill(0, count($chunk), $row)));
            $statement->execute(array_merge(...array_map(array_values(...), $chunk)));
        }
    }

    private static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
