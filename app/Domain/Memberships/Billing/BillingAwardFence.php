<?php

namespace App\Domain\Memberships\Billing;

use Illuminate\Support\Facades\DB;
use LogicException;
use PDO;
use PDOException;

/** One immutable row serializes every retained hint with a first-award proof, including invoice-only hints. */
final class BillingAwardFence
{
    public const TABLE = 'production_membership_billing_award_fence';

    public function table(): string
    {
        $name = $this->name();

        return DB::getDriverName() === 'sqlite' ? 'main."'.$name.'"' : '`'.$name.'`';
    }

    public function eventGuardName(): string
    {
        return DB::connection()->getTablePrefix().BillingSchema::TABLES[3].'_award_fence';
    }

    /** Call before plan, subscription, invoice, observations and hints, on the operation's captured primary. */
    public function lock(PDO $pdo): void
    {
        BillingException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        BillingException::require(DB::connection()->transactionLevel() === 1 && $pdo->inTransaction(), 'award_fence_transaction');
        $this->assertOwned($pdo);
        if (DB::getDriverName() === 'sqlite') {
            // Even an idempotent replay needs writer intent. No row matches, so the immutable-row trigger is not invoked.
            $pdo->exec('UPDATE '.$this->table().' SET id = id WHERE id = 0');
        }
        $rows = $pdo->query('SELECT id FROM '.$this->table().' WHERE id = 1'.(DB::getDriverName() === 'mysql' ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require($rows === [['id' => 1]], 'award_fence');
    }

    public function assertOwned(PDO $pdo): void
    {
        BillingException::require(DB::connection()->getPdo() === $pdo, 'changed_connection');
        $state = $this->inspect($pdo);
        BillingException::require($state['exists'] && $state['seeded'] && $state['missing'] === [], 'award_fence_schema');
    }

    /** Historical migrations admit an absent successor, but validate its event guard if it already exists. */
    public function assertEventGuardIfPresent(PDO $pdo, bool $tablesLocked = false): void
    {
        $this->inspectGuard($pdo, $this->eventGuardName(), $this->eventGuard(), $tablesLocked);
    }

    public function upgrade(): void
    {
        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();
        BillingException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        BillingException::require($connection->transactionLevel() === 0 && ! $pdo->inTransaction(), 'migration_transaction');
        $schema = new BillingSchema;
        $schema->assertRetainedGraph($pdo);
        $state = $this->inspect($pdo);
        // Native DDL cannot be rolled back. Inspect the entire retained graph and every reserved successor name first.
        // Creating only this exact empty table is an explicitly recoverable interruption point before all seven locks exist.
        if ($driver === 'mysql' && ! $state['exists']) {
            $pdo->exec($this->create());
        }
        if ($driver === 'sqlite') {
            $pdo->exec('BEGIN IMMEDIATE');
        } else {
            $tables = array_map(fn (string $table): string => $schema->table($table).' WRITE', BillingSchema::TABLES);
            $tables[] = $this->table().' WRITE';
            $pdo->exec('LOCK TABLES '.implode(', ', $tables));
        }
        try {
            $schema->assertRetainedGraph($pdo, $driver === 'mysql');
            $state = $this->inspect($pdo, $driver === 'mysql');
            if (! $state['exists']) {
                $pdo->exec($this->create());
            }
            if (! $state['seeded']) {
                $pdo->exec('INSERT INTO '.$this->table().' (id) VALUES (1)');
            }
            foreach ($state['missing'] as $guard) {
                $pdo->exec($guard['sql']);
            }
            $state = $this->inspect($pdo, $driver === 'mysql');
            BillingException::require($state['exists'] && $state['seeded'] && $state['missing'] === [], 'award_fence_schema');
            if ($driver === 'sqlite') {
                $pdo->exec('COMMIT');
            }
        } finally {
            if ($driver === 'sqlite') {
                if ($pdo->inTransaction()) {
                    $pdo->exec('ROLLBACK');
                }
            } else {
                $pdo->exec('UNLOCK TABLES');
            }
        }
        $schema->assertOwned($pdo);
    }

    public function down(): never
    {
        throw new LogicException('Production membership billing award ordering requires an explicit retention migration.');
    }

    private function name(): string
    {
        $name = DB::connection()->getTablePrefix().self::TABLE;
        BillingException::require(strlen($name) <= 52 && preg_match('/\A[a-zA-Z0-9_]+\z/D', $name) === 1, 'schema');

        return $name;
    }

    private function create(): string
    {
        $mysql = DB::getDriverName() === 'mysql';
        $quote = $mysql ? '`' : '"';

        return 'CREATE TABLE '.$this->table().' ('.$quote.'id'.$quote.' '.($mysql ? 'INTEGER UNSIGNED' : 'INTEGER').' NOT NULL, PRIMARY KEY (id), CONSTRAINT '.$quote.$this->name().'_bounds'.$quote.' CHECK (id = 1))'
            .($mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '');
    }

    private function guards(): array
    {
        $result = [];
        foreach (['insert', 'update', 'delete'] as $event) {
            $guard = $this->name().'_'.$event;
            $condition = $event === 'insert' ? 'NEW.id = 1 AND NOT EXISTS (SELECT 1 FROM '.$this->table().')' : '0 = 1';
            $body = "BEGIN IF NOT COALESCE(({$condition}),0) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain billing award fence'; END IF; END";
            $sql = DB::getDriverName() === 'mysql'
                ? 'CREATE TRIGGER `'.$guard.'` BEFORE '.strtoupper($event).' ON '.$this->table().' FOR EACH ROW '.$body
                : 'CREATE TRIGGER "'.$guard.'" BEFORE '.strtoupper($event).' ON '.$this->table().' WHEN NOT COALESCE(('.str_replace('main.', '', $condition)."),0) BEGIN SELECT RAISE(ABORT, 'Retain billing award fence'); END";
            $result[$guard] = ['sql' => $sql, 'body' => $body, 'event' => strtoupper($event), 'table' => $this->name()];
        }
        $result[$this->eventGuardName()] = $this->eventGuard();

        return $result;
    }

    private function eventGuard(): array
    {
        $name = DB::connection()->getTablePrefix().BillingSchema::TABLES[3];
        $table = (new BillingSchema)->table(BillingSchema::TABLES[3]);
        $body = 'BEGIN DECLARE fence_id INTEGER DEFAULT NULL; SELECT id INTO fence_id FROM '.$this->table().' WHERE id = 1 FOR UPDATE; '
            ."IF fence_id IS NULL OR fence_id <> 1 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Retain billing award fence'; END IF; END";
        $sql = DB::getDriverName() === 'mysql'
            ? 'CREATE TRIGGER `'.$this->eventGuardName().'` BEFORE INSERT ON '.$table.' FOR EACH ROW PRECEDES `'.$name.'_insert` '.$body
            : 'CREATE TRIGGER "'.$this->eventGuardName().'" BEFORE INSERT ON '.$table.' WHEN NOT EXISTS (SELECT 1 FROM '.str_replace('main.', '', $this->table())." WHERE id = 1) BEGIN SELECT RAISE(ABORT, 'Retain billing award fence'); END";

        return ['sql' => $sql, 'body' => $body, 'event' => 'INSERT', 'table' => $name];
    }

    /** Missing owned guards/seed are recoverable only after every remaining object has passed exact inspection. */
    private function inspect(PDO $pdo, bool $tablesLocked = false): array
    {
        $name = $this->name();
        $guards = $this->guards();
        $driver = DB::getDriverName();
        BillingException::require(in_array($driver, ['sqlite', 'mysql'], true), 'schema');
        if ($driver === 'sqlite') {
            $statement = $pdo->prepare('SELECT name, type, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
            $statement->execute([$name]);
            $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
            BillingException::require($objects === [] || (count($objects) === 1 && $objects[0]['name'] === $name && $objects[0]['type'] === 'table'
                && $this->sql($objects[0]['sql']) === $this->sql(str_replace('main.', '', $this->create()))), 'award_fence_schema');
            $exists = $objects !== [];
            if ($exists) {
                BillingException::require($pdo->query('PRAGMA main.index_list("'.$name.'")')->fetchAll(PDO::FETCH_ASSOC) === [], 'award_fence_schema');
            }
            $statement = $pdo->prepare("SELECT name FROM main.sqlite_master WHERE type = 'trigger' AND tbl_name = ?");
            $statement->execute([$name]);
            BillingException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'award_fence_schema');
            $reserved = array_map(strtolower(...), [$name, ...array_keys($guards)]);
            foreach ($pdo->query('SELECT name, tbl_name FROM sqlite_temp_master')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                BillingException::require(! in_array(strtolower($row['name']), $reserved, true) && ! in_array(strtolower($row['tbl_name']), $reserved, true), 'shadowed_schema');
            }
        } else {
            $statement = $pdo->prepare('SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
            $statement->execute([$name]);
            $objects = $statement->fetchAll(PDO::FETCH_ASSOC);
            BillingException::require($objects === [] || $objects === [['TABLE_NAME' => $name, 'TABLE_TYPE' => 'BASE TABLE', 'ENGINE' => 'InnoDB', 'TABLE_COLLATION' => 'utf8mb4_bin']], 'award_fence_schema');
            $exists = $objects !== [];
            $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)');
            $statement->execute([$name]);
            BillingException::require($statement->fetchAll(PDO::FETCH_COLUMN) === [], 'schema_namespace');
            try {
                $shown = $pdo->query('SHOW CREATE TABLE '.$this->table())->fetch(PDO::FETCH_NUM);
                BillingException::require(! str_contains(strtoupper($shown[1]), 'TEMPORARY'), 'shadowed_schema');
            } catch (PDOException $error) {
                if ($exists || $error->getCode() !== '42S02') {
                    throw $error;
                }
            }
            if ($exists) {
                $this->mysqlDefinition($pdo);
            }
            $statement = $pdo->prepare('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND EVENT_OBJECT_TABLE = ?');
            $statement->execute([$name]);
            BillingException::require(array_diff($statement->fetchAll(PDO::FETCH_COLUMN), array_keys($guards)) === [], 'award_fence_schema');
            $statement = $pdo->prepare('SELECT CONSTRAINT_NAME, TABLE_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY CONSTRAINT_SCHEMA = BINARY DATABASE() AND CONSTRAINT_TYPE = ? AND CONSTRAINT_NAME = ?');
            $statement->execute(['CHECK', $name.'_bounds']);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            BillingException::require($rows === [] || $rows === [['CONSTRAINT_NAME' => $name.'_bounds', 'TABLE_NAME' => $name, 'CONSTRAINT_TYPE' => 'CHECK']], 'schema_namespace');
        }
        $missing = [];
        foreach ($guards as $guard => $expected) {
            $present = $this->inspectGuard($pdo, $guard, $expected, $tablesLocked);
            BillingException::require($exists || ! $present, 'award_fence_schema');
            if (! $present) {
                $missing[] = $expected;
            }
        }
        $rows = $exists ? $pdo->query('SELECT id FROM '.$this->table().' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
        BillingException::require($rows === [] || $rows === [['id' => 1]], 'award_fence_schema');

        return ['exists' => $exists, 'seeded' => $rows !== [], 'missing' => $missing];
    }

    private function inspectGuard(PDO $pdo, string $guard, array $expected, bool $tablesLocked): bool
    {
        if (DB::getDriverName() === 'sqlite') {
            $statement = $pdo->prepare('SELECT name, type, tbl_name, sql FROM main.sqlite_master WHERE lower(name) = lower(?)');
            $statement->execute([$guard]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            BillingException::require($rows === [] || (count($rows) === 1 && $rows[0]['name'] === $guard && $rows[0]['type'] === 'trigger'
                && $rows[0]['tbl_name'] === $expected['table'] && $this->sql($rows[0]['sql']) === $this->sql($expected['sql'])), 'award_fence_schema');
            $statement = $pdo->prepare('SELECT name FROM sqlite_temp_master WHERE lower(name) = lower(?)');
            $statement->execute([$guard]);
            BillingException::require($statement->fetchAll(PDO::FETCH_COLUMN) === [], 'shadowed_schema');
        } else {
            $statement = $pdo->prepare('SELECT TRIGGER_NAME, EVENT_OBJECT_TABLE, EVENT_MANIPULATION, ACTION_TIMING, ACTION_STATEMENT, ACTION_ORDER FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY DATABASE() AND lower(TRIGGER_NAME) = lower(?)');
            $statement->execute([$guard]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
            BillingException::require($rows === [] || (count($rows) === 1 && $rows[0]['TRIGGER_NAME'] === $guard && $rows[0]['EVENT_OBJECT_TABLE'] === $expected['table']
                && $rows[0]['EVENT_MANIPULATION'] === $expected['event'] && $rows[0]['ACTION_TIMING'] === 'BEFORE' && (int) $rows[0]['ACTION_ORDER'] === 1
                && $this->sql($rows[0]['ACTION_STATEMENT']) === $this->sql($expected['body'])), 'award_fence_schema');
            $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND lower(TABLE_NAME) = lower(?)');
            $statement->execute([$guard]);
            BillingException::require($statement->fetchAll(PDO::FETCH_COLUMN) === [], 'schema_namespace');
            if (! $tablesLocked) {
                try {
                    $pdo->query('SHOW CREATE TABLE `'.$guard.'`')->fetchAll();
                    throw new BillingException('schema_namespace');
                } catch (PDOException $error) {
                    if ($error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
            }
        }

        return $rows !== [];
    }

    private function mysqlDefinition(PDO $pdo): void
    {
        $name = $this->name();
        $statement = $pdo->prepare('SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, COLLATION_NAME FROM information_schema.COLUMNS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION');
        $statement->execute([$name]);
        BillingException::require($statement->fetchAll(PDO::FETCH_ASSOC) === [['COLUMN_NAME' => 'id', 'COLUMN_TYPE' => 'int unsigned', 'IS_NULLABLE' => 'NO', 'COLUMN_DEFAULT' => null, 'EXTRA' => '', 'COLLATION_NAME' => null]], 'award_fence_schema');
        $indexes = $pdo->query('SHOW INDEX FROM '.$this->table())->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(count($indexes) === 1 && $indexes[0]['Key_name'] === 'PRIMARY' && $indexes[0]['Column_name'] === 'id'
            && $indexes[0]['Index_type'] === 'BTREE' && (int) $indexes[0]['Non_unique'] === 0 && $indexes[0]['Sub_part'] === null, 'award_fence_schema');
        $statement = $pdo->prepare('SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE BINARY TABLE_SCHEMA = BINARY DATABASE() AND TABLE_NAME = ?');
        $statement->execute([$name]);
        $constraints = array_column($statement->fetchAll(PDO::FETCH_ASSOC), 'CONSTRAINT_TYPE', 'CONSTRAINT_NAME');
        ksort($constraints);
        $expected = ['PRIMARY' => 'PRIMARY KEY', $name.'_bounds' => 'CHECK'];
        ksort($expected);
        BillingException::require($constraints === $expected, 'award_fence_schema');
        $statement = $pdo->prepare("SELECT c.CHECK_CLAUSE, t.ENFORCED FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t ON BINARY t.CONSTRAINT_SCHEMA = BINARY c.CONSTRAINT_SCHEMA AND BINARY t.CONSTRAINT_NAME = BINARY c.CONSTRAINT_NAME WHERE BINARY t.TABLE_SCHEMA = BINARY DATABASE() AND BINARY t.TABLE_NAME = BINARY ? AND t.CONSTRAINT_TYPE = 'CHECK'");
        $statement->execute([$name]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        BillingException::require(count($rows) === 1 && $rows[0]['ENFORCED'] === 'YES' && strtolower(preg_replace('/[\s`()]+/', '', $rows[0]['CHECK_CLAUSE'])) === 'id=1', 'award_fence_schema');
    }

    private function sql(string $sql): string
    {
        return trim(preg_replace('/\s+/', ' ', $sql), ' ;');
    }
}
