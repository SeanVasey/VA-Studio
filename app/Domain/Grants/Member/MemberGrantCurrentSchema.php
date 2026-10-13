<?php

namespace App\Domain\Grants\Member;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipAwardTransaction;
use App\Domain\Memberships\Production\MembershipRows;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Facade;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;
use Throwable;
use WeakMap;

/** Current native definitions, pinned before records. No migration, repair or caller-defined expected plan. */
final class MemberGrantCurrentSchema
{
    private static ?WeakMap $issued = null;

    private const SQLITE_EXTENSIONS = ['bm25', 'fts3_tokenizer', 'fts5', 'fts5_source_id', 'highlight', 'match',
        'matchinfo', 'offsets', 'optimize', 'rtreecheck', 'rtreedepth', 'rtreenode', 'snippet'];

    public static function pin(MembershipRows $rows): MemberGrantSchemaSnapshot
    {
        // Callback admission belongs here, before this snapshot enters any terminal raw phase.
        $rows->assertCurrent();
        $state = ['rows' => $rows];
        foreach (['container', 'manager', 'configuration', 'connection', 'primary', 'driver', 'schema', 'prefix', 'connectionName'] as $name) {
            $state[$name] = self::property($rows, MembershipRows::class, $name);
        }
        $state['plan'] = MemberGrantSchema::expectedPlan($state['driver'], $state['schema'], $state['prefix']);
        $items = self::property($state['configuration'], Repository::class, 'items');
        $state['configurationItems'] = $items['database'] ?? null;
        $state['keyConfig'] = [$items['app']['key'] ?? null, $items['app']['previous_keys'] ?? null];
        $state['connectionConfig'] = self::property($state['connection'], Connection::class, 'config');
        $owned = self::property($rows, MembershipRows::class, 'ownedTransaction');
        if ($owned === null) {
            $state['marker'] = self::property($rows, MembershipRows::class, 'marker');
        } else {
            MemberGrantException::require($owned instanceof MembershipAwardTransaction
                && self::property($owned, MembershipAwardTransaction::class, 'primary') === $state['primary']
                && self::property($owned, MembershipAwardTransaction::class, 'connection') === $state['connection'], 'changed_primary');
            $state['marker'] = self::property($owned, MembershipAwardTransaction::class, 'marker');
        }
        MemberGrantException::require(is_string($state['marker'])
            && preg_match('/\A(?:membership_|va_membership_award_)[a-f0-9]{32}\z/D', $state['marker']) === 1, 'changed_primary');
        try {
            self::context($state, 1);
            self::marker($state['primary'], $state['marker']);
            self::inspect($state);
            self::context($state, 1);
            self::marker($state['primary'], $state['marker']);
        } catch (Throwable $error) {
            throw new MemberGrantException($error instanceof MemberGrantException ? $error->reason : 'schema');
        }
        $snapshot = new MemberGrantSchemaSnapshot;
        self::$issued ??= new WeakMap;
        self::$issued[$snapshot] = $state;

        return $snapshot;
    }

    /** Pure fixed-name lookup, never a caller-selected table or plan. */
    public static function table(MemberGrantSchemaSnapshot $snapshot, string $logical): string
    {
        $state = self::state($snapshot);
        MemberGrantException::require(isset($state['plan']['tables'][$logical]), 'schema_snapshot');

        return $state['plan']['tables'][$logical]['table'];
    }

    public static function captureDriver(MemberGrantSchemaSnapshot $snapshot): string
    {
        return self::state($snapshot)['driver'];
    }

    public static function assertCurrent(MemberGrantSchemaSnapshot $snapshot, MembershipRows $rows): void
    {
        $state = self::state($snapshot);
        MemberGrantException::require($state['rows'] === $rows, 'schema_snapshot');
        try {
            self::context($state, 1);
            self::marker($state['primary'], $state['marker']);
            self::inspect($state);
            self::context($state, 1);
            self::marker($state['primary'], $state['marker']);
        } catch (Throwable $error) {
            throw new MemberGrantException($error instanceof MemberGrantException ? $error->reason : 'schema');
        }
    }

    /** Raw only: the facts issuer registers this frame in its preceding callback phase. Never calls frame getters or finish(). */
    public static function assertCommitted(MemberGrantSchemaSnapshot $snapshot, IdentityCommittedFrame $frame): void
    {
        $state = self::state($snapshot);
        try {
            self::committedContext($state, $frame);
            self::inspect($state);
            self::committedContext($state, $frame);
        } catch (Throwable $error) {
            throw new MemberGrantException($error instanceof MemberGrantException ? $error->reason : 'schema');
        }
    }

    private static function state(MemberGrantSchemaSnapshot $snapshot): array
    {
        MemberGrantException::require(self::$issued !== null && isset(self::$issued[$snapshot]), 'schema_snapshot');

        return self::$issued[$snapshot];
    }

    private static function committedContext(array $state, IdentityCommittedFrame $frame): void
    {
        self::context($state, 0);
        $read = static fn (string $name): mixed => self::property($frame, IdentityCommittedFrame::class, $name);
        $reader = $read('reader');
        MemberGrantException::require($read('connection') === $state['connection'] && $read('primary') === $state['primary']
            && $read('driver') === $state['driver'] && $read('schema') === $state['schema']
            && $read('used') === false && $read('closed') === false && \hrtime(true) < $read('originalDeadlineNs')
            && self::property($reader, CurrentRows::class, 'primary') === $state['primary']
            && self::property($reader, CurrentRows::class, 'driver') === $state['driver'], 'committed_frame_required');
        $pdo = $state['primary'];
        if ($state['driver'] === 'sqlite') {
            MemberGrantException::require((int) $pdo->query('PRAGMA query_only')->fetchColumn() === 1, 'committed_frame_required');
        } else {
            MemberGrantException::require($pdo->query('SELECT @@SESSION.transaction_isolation isolation_level,@@SESSION.transaction_read_only read_only')->fetch(PDO::FETCH_ASSOC)
                === $read('defaults'), 'committed_frame_required');
        }
        $sentinel = $read('sentinel');
        MemberGrantException::require(is_string($sentinel) && preg_match('/\Ava_identity_frame_[a-f0-9]{32}\z/D', $sentinel) === 1, 'committed_frame_required');
        self::marker($pdo, $sentinel);
    }

    /** Release first proves original transaction identity. Never recreate a missing marker or rewind consumer writes. */
    private static function marker(PDO $pdo, string $marker): void
    {
        $pdo->exec('RELEASE SAVEPOINT '.$marker);
        $pdo->exec('SAVEPOINT '.$marker);
    }

    /** Captured native objects and raw properties only: no container resolution or virtual framework getter. */
    private static function context(array $state, int $level): void
    {
        $pdo = $state['primary'];
        MemberGrantException::require(in_array(get_class($pdo), [PDO::class, Mysql::class, Sqlite::class], true)
            && $pdo->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && in_array($pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE), [PDO::FETCH_BOTH, PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_OBJ], true)
            && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === $state['driver'] && $pdo->inTransaction(), 'changed_primary');
        self::sqliteCallbacks($pdo, $state['driver']);
        $instances = self::property($state['container'], Container::class, 'instances');
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $connections = self::property($state['manager'], DatabaseManager::class, 'connections');
        $items = self::property($state['configuration'], Repository::class, 'items');
        MemberGrantException::require((new ReflectionProperty(Container::class, 'instance'))->getValue() === $state['container']
            && is_array($instances) && is_array($resolved) && is_array($connections) && is_array($items)
            && ($instances['db'] ?? null) === $state['manager'] && ($resolved['db'] ?? null) === $state['manager']
            && ($instances['config'] ?? null) === $state['configuration']
            && ($connections[$state['connectionName']] ?? null) === $state['connection']
            && ($items['database'] ?? null) === $state['configurationItems']
            && [$items['app']['key'] ?? null, $items['app']['previous_keys'] ?? null] === $state['keyConfig']
            && ($items['database']['default'] ?? null) === $state['connectionName']
            && self::property($state['connection'], Connection::class, 'pdo') === $pdo
            && self::property($state['connection'], Connection::class, 'transactions') === $level
            && self::property($state['connection'], Connection::class, 'tablePrefix') === $state['prefix']
            && self::property($state['connection'], Connection::class, 'config') === $state['connectionConfig'], 'changed_connection');
        MemberGrantException::require($state['driver'] === 'mysql'
            || (($items['production-memberships']['provenance'] ?? null) !== 'verified_production'
                && ($items['member-grants']['provenance'] ?? null) !== 'verified_production'), 'provenance_driver');
        if ($level === 0) {
            foreach ($connections as $connection) {
                MemberGrantException::require($connection instanceof Connection, 'changed_connection');
                $raw = self::property($connection, Connection::class, 'pdo');
                MemberGrantException::require($raw instanceof PDO && in_array(get_class($raw), [PDO::class, Mysql::class, Sqlite::class], true)
                    && ($connection === $state['connection'] || (self::property($connection, Connection::class, 'transactions') === 0 && ! $raw->inTransaction())), 'changed_connection');
            }
        }
        if ($state['driver'] === 'mysql') {
            MemberGrantException::require($pdo->query('SELECT DATABASE()')->fetchColumn() === $state['schema'], 'changed_schema');
        }
    }

    private static function property(object $object, string $class, string $name): mixed
    {
        return (new ReflectionProperty($class, $name))->getValue($object);
    }

    private static function sqliteCallbacks(PDO $pdo, string $driver): void
    {
        if ($driver !== 'sqlite') {
            return;
        }
        $builtin = [];
        $application = [];
        foreach ($pdo->query('SELECT name,builtin FROM pragma_function_list')->fetchAll(PDO::FETCH_NUM) as $function) {
            MemberGrantException::require(count($function) === 2 && is_string($function[0]), 'sqlite_callback_catalog');
            if ((string) $function[1] === '1') {
                $builtin[strtolower($function[0])] = true;
            } else {
                $application[] = strtolower($function[0]);
            }
        }
        MemberGrantException::require($builtin !== [], 'sqlite_callback_catalog');
        foreach ($application as $name) {
            MemberGrantException::require(in_array($name, self::SQLITE_EXTENSIONS, true) && ! isset($builtin[$name]), 'sqlite_application_function');
        }
        $collations = array_column($pdo->query('PRAGMA collation_list')->fetchAll(PDO::FETCH_NUM), 1);
        sort($collations);
        MemberGrantException::require($collations === ['BINARY', 'NOCASE', 'RTRIM'], 'sqlite_application_collation');
    }

    private static function inspect(array $state): void
    {
        // Open every qualified relation first. MySQL retains its metadata locks for this physical transaction.
        foreach ($state['plan']['tables'] as $table) {
            $state['primary']->query('SELECT 1 FROM '.$table['table'].' LIMIT 0')->closeCursor();
        }
        if ($state['driver'] === 'sqlite') {
            self::sqlite($state);
        } else {
            self::mysql($state);
        }
    }

    private static function sqlite(array $state): void
    {
        $pdo = $state['primary'];
        $reserved = [];
        $expected = [];
        $tableNames = [];
        foreach ($state['plan']['tables'] as $table) {
            $name = $table['name'];
            $tableNames[] = strtolower($name);
            $expected[$name] = ['type' => 'table', 'tbl_name' => $name, 'sql' => str_replace('CREATE TABLE main.', 'CREATE TABLE ', $table['create'])];
            foreach ($table['guards'] as $guard => $definition) {
                $expected[$guard] = ['type' => 'trigger', 'tbl_name' => $name, 'sql' => $definition['sql']];
            }
            for ($index = 1; $index <= 1 + count($table['definition']['unique']); $index++) {
                $expected['sqlite_autoindex_'.$name.'_'.$index] = ['type' => 'index', 'tbl_name' => $name, 'sql' => null];
            }
        }
        foreach ($expected as $name => $_) {
            $reserved[strtolower($name)] = $name;
        }
        foreach ($pdo->query('SELECT name,type,tbl_name,sql FROM temp.sqlite_master')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            MemberGrantException::require(! isset($reserved[strtolower($row['name'])]) && ! in_array(strtolower($row['tbl_name']), $tableNames, true), 'shadowed_schema');
        }
        $seen = [];
        foreach ($pdo->query('SELECT name,type,tbl_name,sql FROM main.sqlite_master')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower($row['name']);
            if (isset($reserved[$key])) {
                $wanted = $expected[$reserved[$key]];
                MemberGrantException::require(! isset($seen[$key]) && $row['name'] === $reserved[$key]
                    && $row['type'] === $wanted['type'] && $row['tbl_name'] === $wanted['tbl_name']
                    && ($wanted['sql'] === null ? $row['sql'] === null : is_string($row['sql']) && MemberGrantSqlDefinition::same($row['sql'], $wanted['sql'])), 'schema');
                $seen[$key] = true;
            } else {
                MemberGrantException::require(! in_array(strtolower($row['tbl_name']), $tableNames, true), 'schema_namespace');
            }
        }
        MemberGrantException::require(count($seen) === count($expected), 'schema');
    }

    private static function mysql(array $state): void
    {
        $pdo = $state['primary'];
        $reserved = [];
        foreach ($state['plan']['tables'] as $table) {
            $reserved[strtolower($table['name'])] = ['name' => $table['name'], 'type' => 'table'];
            $shown = $pdo->query('SHOW CREATE TABLE '.$table['table'])->fetch(PDO::FETCH_NUM);
            MemberGrantException::require(is_array($shown) && count($shown) === 2 && $shown[0] === $table['name'] && is_string($shown[1]), 'schema');
            MemberGrantSqlDefinition::mysqlTable($shown[1], $table, $state['prefix']);
            foreach ($table['guards'] as $guard => $definition) {
                $reserved[strtolower($guard)] = ['name' => $guard, 'type' => 'trigger'];
                $qualified = '`'.$state['schema'].'`.`'.$guard.'`';
                $shown = $pdo->query('SHOW CREATE TRIGGER '.$qualified)->fetch(PDO::FETCH_ASSOC);
                MemberGrantException::require(is_array($shown) && ($shown['Trigger'] ?? null) === $guard && is_string($shown['SQL Original Statement'] ?? null), 'schema');
                MemberGrantSqlDefinition::mysqlTrigger($shown['SQL Original Statement'], $definition['sql']);
                // Native triggers and tables occupy separate namespaces; the member reservation owns both spellings.
                try {
                    $pdo->query('SHOW CREATE TABLE '.$qualified)->fetchAll(PDO::FETCH_NUM);
                    throw new MemberGrantException('schema_namespace');
                } catch (\PDOException $error) {
                    if ($error->getCode() !== '42S02') {
                        throw $error;
                    }
                }
            }
        }
        // Supplementary namespace/unknown-trigger inventory. RR may retain this inventory; all owned
        // table structures and all 16 required known triggers above use current SHOW, never this snapshot.
        $statement = $pdo->prepare("SELECT TABLE_NAME name, 'table' type, TABLE_NAME owner FROM information_schema.TABLES WHERE BINARY TABLE_SCHEMA = BINARY ? UNION ALL SELECT TRIGGER_NAME name, 'trigger' type, EVENT_OBJECT_TABLE owner FROM information_schema.TRIGGERS WHERE BINARY TRIGGER_SCHEMA = BINARY ?");
        $statement->execute([$state['schema'], $state['schema']]);
        $seen = [];
        $names = array_column($state['plan']['tables'], 'name');
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = strtolower($row['name']);
            if (isset($reserved[$key])) {
                MemberGrantException::require(! isset($seen[$key]) && $reserved[$key] === ['name' => $row['name'], 'type' => $row['type']], 'schema_namespace');
                $seen[$key] = true;
            } elseif ($row['type'] === 'trigger') {
                MemberGrantException::require(! in_array($row['owner'], $names, true), 'schema_namespace');
            }
        }
    }
}
