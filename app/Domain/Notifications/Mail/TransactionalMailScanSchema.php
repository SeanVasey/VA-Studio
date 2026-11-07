<?php

namespace App\Domain\Notifications\Mail;

use LogicException;
use PDO;
use PDOException;

/** Owned operational positions only; never customer authority or a delivery acknowledgment. */
final class TransactionalMailScanSchema
{
    public const TABLE = 'transactional_mail_scan_progress';

    public const PURPOSES = ['new_intents', 'queued_wakeups'];

    public const MAX = 9223372036854775806;

    public static function definition(string $driver): string
    {
        if (! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new LogicException('Mail scan progress requires SQLite or MySQL.');
        }
        $key = $driver === 'mysql' ? 'varchar(24) CHARACTER SET ascii COLLATE ascii_bin' : 'varchar(24) COLLATE BINARY';
        $number = $driver === 'mysql' ? 'bigint unsigned' : 'integer';

        return 'CREATE TABLE '.self::TABLE.' (scan_key '.$key.' NOT NULL, cycle '.$number.' NOT NULL, after_id '.$number
            .' NOT NULL, high_water_id '.$number.' NOT NULL, version '.$number.' NOT NULL, PRIMARY KEY (scan_key))'
            .($driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '');
    }

    public static function guards(string $driver): array
    {
        $key = $driver === 'mysql' ? 'BINARY NEW.scan_key' : 'NEW.scan_key';
        $shape = $key." IN ('new_intents', 'queued_wakeups') AND NEW.after_id <= NEW.high_water_id";
        foreach (['cycle', 'after_id', 'high_water_id', 'version'] as $field) {
            $shape .= ' AND NEW.'.$field.' BETWEEN 0 AND '.self::MAX;
            if ($driver === 'sqlite') {
                $shape .= " AND typeof(NEW.$field) = 'integer'";
            }
        }
        $sameKey = $driver === 'mysql' ? 'BINARY NEW.scan_key = BINARY OLD.scan_key' : 'NEW.scan_key IS OLD.scan_key';
        $insert = $shape.' AND NEW.cycle = 0 AND NEW.after_id = 0 AND NEW.high_water_id = 0 AND NEW.version = 0'
            .' AND NOT EXISTS (SELECT 1 FROM '.self::TABLE.' WHERE scan_key = NEW.scan_key)';
        $update = $shape.' AND '.$sameKey.' AND NEW.version = OLD.version + 1 AND ('
            .'(NEW.cycle = OLD.cycle AND NEW.high_water_id = OLD.high_water_id AND NEW.after_id > OLD.after_id) OR '
            .'(OLD.after_id = OLD.high_water_id AND NEW.cycle = OLD.cycle + 1 AND NEW.after_id = 0))';
        $guards = [];
        foreach (['insert' => $insert, 'update' => $update, 'delete' => null] as $operation => $valid) {
            $name = self::TABLE.'_'.$operation;
            $message = 'Mail scan progress is invalid or retained';
            $guards[$name] = $driver === 'sqlite'
                ? 'CREATE TRIGGER '.$name.' BEFORE '.strtoupper($operation).' ON '.self::TABLE
                    .($valid === null ? '' : ' WHEN NOT COALESCE(('.$valid.'), 0)')." BEGIN SELECT RAISE(ABORT, '$message'); END"
                : 'CREATE TRIGGER '.$name.' BEFORE '.strtoupper($operation).' ON '.self::TABLE.' FOR EACH ROW BEGIN '
                    .($valid === null ? '' : 'IF NOT COALESCE(('.$valid.'), 0) THEN ')
                    ."SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$message'; ".($valid === null ? '' : 'END IF; ').'END';
        }

        return $guards;
    }

    /** Refuse every reserved-name collision before the first owned DDL, including partial installations. */
    public static function assertAbsent(PDO $pdo, string $driver): void
    {
        self::definition($driver);
        if ($driver === 'mysql') {
            try {
                $pdo->query('SHOW CREATE TABLE '.self::TABLE);
                throw new LogicException('Existing or temporary mail scan storage is refused.');
            } catch (PDOException $error) {
                if (($error->errorInfo[1] ?? null) !== 1146) {
                    throw $error;
                }
            }
        }
        foreach ([self::TABLE, ...array_keys(self::guards($driver))] as $name) {
            if ($driver === 'sqlite') {
                foreach (['main', 'temp'] as $schema) {
                    $q = $pdo->prepare('SELECT name FROM '.$schema.'.sqlite_schema WHERE name = ? COLLATE NOCASE');
                    $q->execute([$name]);
                    if ($q->fetchAll() !== []) {
                        throw new LogicException('Retained or foreign mail scan objects require inspection.');
                    }
                }
            } else {
                foreach ([['TABLES', 'TABLE_SCHEMA', 'TABLE_NAME'], ['TRIGGERS', 'TRIGGER_SCHEMA', 'TRIGGER_NAME'],
                    ['STATISTICS', 'TABLE_SCHEMA', 'INDEX_NAME'], ['TABLE_CONSTRAINTS', 'CONSTRAINT_SCHEMA', 'CONSTRAINT_NAME'],
                    ['ROUTINES', 'ROUTINE_SCHEMA', 'ROUTINE_NAME'], ['EVENTS', 'EVENT_SCHEMA', 'EVENT_NAME']] as [$table, $schema, $column]) {
                    $q = $pdo->prepare('SELECT '.$column.' FROM information_schema.'.$table.' WHERE '.$schema.' = DATABASE() AND '.$column.' = ?');
                    $q->execute([$name]);
                    if ($q->fetchAll() !== []) {
                        throw new LogicException('Retained or foreign mail scan objects require inspection.');
                    }
                }
            }
        }
    }

    /** Compare the complete table/guard definitions; do not adopt a foreign or partially installed cursor. */
    public static function assertOwned(PDO $pdo, string $driver): void
    {
        self::assertDependencies($pdo, $driver);
        if ($driver === 'sqlite') {
            $q = $pdo->prepare('SELECT sql FROM main.sqlite_schema WHERE name = ? AND type = ?');
            $q->execute([self::TABLE, 'table']);
            $definition = $q->fetchColumn();
            $q = $pdo->prepare('SELECT name, sql FROM main.sqlite_schema WHERE tbl_name = ? AND type = ? ORDER BY name');
            $q->execute([self::TABLE, 'trigger']);
            $guards = $q->fetchAll(PDO::FETCH_KEY_PAIR);
            $q = $pdo->prepare('SELECT name FROM temp.sqlite_schema WHERE name = ? COLLATE NOCASE OR tbl_name = ? COLLATE NOCASE');
            $q->execute([self::TABLE, self::TABLE]);
            if ($q->fetchAll() !== []) {
                throw new LogicException('Temporary mail scan storage is refused.');
            }
            $q = $pdo->prepare('SELECT name FROM main.sqlite_schema WHERE tbl_name = ? AND type = ? AND sql IS NOT NULL');
            $q->execute([self::TABLE, 'index']);
            if ($q->fetchAll() !== []) {
                throw new LogicException('Foreign mail scan indexes are refused.');
            }
        } elseif ($driver === 'mysql') {
            $definition = $pdo->query('SHOW CREATE TABLE '.self::TABLE)->fetch(PDO::FETCH_NUM)[1] ?? null;
            $q = $pdo->prepare('SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = ? ORDER BY TRIGGER_NAME');
            $q->execute([self::TABLE]);
            $bodies = $q->fetchAll(PDO::FETCH_ASSOC);
            $guards = [];
            foreach ($bodies as $body) {
                $name = $body['TRIGGER_NAME'];
                $guards[$name] = 'CREATE TRIGGER '.$name.' '.$body['ACTION_TIMING'].' '.$body['EVENT_MANIPULATION'].' ON '.self::TABLE.' FOR EACH ROW '.$body['ACTION_STATEMENT'];
            }
        } else {
            throw new LogicException('Mail scan driver is refused.');
        }
        $expected = self::guards($driver);
        ksort($expected);
        if (! is_string($definition) || self::tokens($definition) !== self::tokens(self::definition($driver))
            || array_keys($guards) !== array_keys($expected)) {
            throw new LogicException('Mail scan schema requires inspection.');
        }
        foreach ($expected as $name => $sql) {
            if (self::tokens($guards[$name]) !== self::tokens($sql)) {
                throw new LogicException('Mail scan guard requires inspection.');
            }
        }
        $keys = $pdo->query('SELECT scan_key FROM '.self::TABLE.' ORDER BY scan_key')->fetchAll(PDO::FETCH_COLUMN);
        if ($keys !== self::PURPOSES) {
            throw new LogicException('Mail scan purposes require inspection.');
        }
    }

    public static function assertDependencies(PDO $pdo, string $driver): void
    {
        foreach (['transactional_notices', 'transactional_mail_deliveries', 'customer_accounts'] as $table) {
            if ($driver === 'sqlite') {
                $q = $pdo->prepare('SELECT name FROM main.sqlite_schema WHERE name = ? AND type = ?');
                $q->execute([$table, 'table']);
                if ($q->fetchAll(PDO::FETCH_COLUMN) !== [$table]) {
                    throw new LogicException('Mail scan dependencies require inspection.');
                }
                $q = $pdo->prepare('SELECT name FROM temp.sqlite_schema WHERE name = ? COLLATE NOCASE');
                $q->execute([$table]);
                if ($q->fetchAll() !== []) {
                    throw new LogicException('Temporary mail scan dependencies are refused.');
                }
            } elseif ($driver === 'mysql') {
                $definition = $pdo->query('SHOW CREATE TABLE '.$table)->fetch(PDO::FETCH_NUM)[1] ?? '';
                if (! str_starts_with($definition, 'CREATE TABLE ') || ! str_contains($definition, 'ENGINE=InnoDB')) {
                    throw new LogicException('Permanent transactional mail scan dependencies are required.');
                }
            } else {
                throw new LogicException('Mail scan driver is refused.');
            }
        }
    }

    private static function tokens(string $sql): array
    {
        preg_match_all("/'(?:''|[^'])*'|[a-zA-Z_][a-zA-Z_0-9]*|[0-9]+|[^\\s`]/", $sql, $matches);

        return array_map(fn (string $token): string => str_starts_with($token, "'") ? $token : strtolower($token), $matches[0]);
    }
}
