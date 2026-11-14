<?php

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;

/** Diagnostic counting only; production canonical factory admission is not exercised. */
final class CompanionCountingPDO extends PDO
{
    public int $sqlCalls = 0;

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->sqlCalls++;

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->sqlCalls++;

        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        $this->sqlCalls++;

        return parent::exec($statement);
    }
}
// Raw, finite, synthetic metadata experiment. No application autoload/bootstrap.
final class RRNSPreparedStatement extends PDOStatement
{
    protected function __construct(private string $role) {}

    public function execute(?array $params = null): bool
    {
        emit(['event' => 'prepared_sql', 'role' => $this->role, 'id' => $GLOBALS['rrns_statement_id'] ?? null, 'sql' => $this->queryString, 'bindings' => $params]);

        return parent::execute($params);
    }
}
/** Fixed diagnostic command only. Native candidate/primitive preparation is never changed. */
function directWarnings(PDO $pdo, ?callable $record = null): array
{
    $mode = ['protocol' => 'COM_QUERY_WARNING_ONLY', 'before' => null, 'during' => null, 'after' => null, 'restored' => false, 'stage' => 'read_before'];
    try {
        $before = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
    } catch (Throwable $error) {
        if ($record !== null) {
            $record($mode);
        } throw $error;
    }
    $mode['before'] = $before;
    if ($before !== false) {
        if ($record !== null) {
            $record($mode);
        } throw new RuntimeException('Warning diagnostic requires nativefalse before entry');
    }
    try {
        $mode['stage'] = 'set_warning_mode';
        if (! $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true)) {
            throw new RuntimeException('Warning diagnostic mode set failed');
        }
        $mode['during'] = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
        if ($mode['during'] !== true) {
            throw new RuntimeException('Warning diagnostic direct mode not established');
        }
        $mode['stage'] = 'query';
        $statement = $pdo->query('SHOW WARNINGS');
        if (! $statement instanceof PDOStatement) {
            throw new RuntimeException('Warning diagnostic statement unavailable');
        }
        $mode['stage'] = 'fetch';
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $mode['stage'] = 'close';
        if (! $statement->closeCursor()) {
            throw new RuntimeException('Warning diagnostic cursor close failed');
        }
        $mode['stage'] = 'complete';
    } finally {
        try {
            $restore = $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, $before);
            $mode['after'] = $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES);
            $mode['restored'] = $restore && $mode['after'] === $before && $mode['after'] === false;
            if (! $mode['restored']) {
                throw new RuntimeException('Warning diagnostic exact native mode restoration failed');
            }
        } finally {
            if ($record !== null) {
                $record($mode);
            }
        }
    }

    return ['rows' => $rows, 'diagnostic_mode' => $mode];
}
function schemas(string $token): array
{
    if (! preg_match('/\A[a-f0-9]{12}\z/', $token)) {
        throw new RuntimeException('Invalid internal token');
    }
    $selected = 'va_acmeta_20261102_'.$token;

    return [$selected, $selected.'-peer'];
}
function qi(string $name): string
{
    return '`'.str_replace('`', '``', $name).'`';
}
function catalog(string $schema, string $lock = ''): array
{
    if (! in_array($lock, ['', ' FOR SHARE', ' FOR UPDATE'], true)) {
        throw new RuntimeException('Unknown fixed arm');
    }
    $four = "('customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')";
    $five = "('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')";

    return [
        'tables' => "SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA='$schema' AND TABLE_NAME IN $four$lock",
        'triggers' => "SELECT TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_TIMING,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$schema' AND TRIGGER_NAME IN $five AND BINARY EVENT_OBJECT_TABLE<>BINARY 'customer_accounts'$lock",
        'routines' => "SELECT ROUTINE_NAME,ROUTINE_TYPE,ROUTINE_DEFINITION FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$schema' AND ROUTINE_NAME IN $five$lock",
        'events' => "SELECT EVENT_NAME,STATUS,EVENT_DEFINITION FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$schema' AND EVENT_NAME IN $five$lock",
        'indexes' => "SELECT TABLE_NAME,INDEX_NAME,COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$schema' AND INDEX_NAME IN $five$lock",
        'constraints' => "SELECT TABLE_NAME,CONSTRAINT_NAME,CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='$schema' AND CONSTRAINT_NAME IN $five$lock",
        'peer_scope' => "SELECT TRIGGER_SCHEMA,TRIGGER_NAME,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA IN ('$schema','$schema-peer') AND TRIGGER_NAME IN ('peer_own_anchor','peer_qualified_anchor','peer_target_anchor')$lock",
    ];
}
function specs(array $m): array
{
    [$s,$p] = schemas($m['token']);
    $q = qi($s);
    $peer = qi($p);
    $base = [
        'pre.schemas' => "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME IN ('$s','$p')",
        'create.selected' => "CREATE DATABASE $q CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'create.peer' => "CREATE DATABASE $peer CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci",
        'base.anchor' => "CREATE TABLE $q.snapshot_anchor(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.anchor.row' => "INSERT INTO $q.snapshot_anchor VALUES(1)",
        'base.account' => "CREATE TABLE $q.customer_accounts(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.account.row' => "INSERT INTO $q.customer_accounts VALUES(1)",
        'base.parent' => "CREATE TABLE $q.foreign_parent(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.parent.row' => "INSERT INTO $q.foreign_parent VALUES(1)",
        'base.target' => "CREATE TABLE $q.foreign_target(id INT PRIMARY KEY,v INT,visible_value INT,reserved_value INT,unique_value INT,parent_id INT,KEY plain_parent_support(parent_id)) ENGINE=InnoDB",
        'base.peer.anchor' => "CREATE TABLE $peer.snapshot_anchor(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.peer.target' => "CREATE TABLE $peer.foreign_target(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.visible.table' => "CREATE TABLE $q.visible_control(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.visible.trigger' => "CREATE TRIGGER $q.visible_control AFTER INSERT ON $q.foreign_target FOR EACH ROW SET @rrns_visible=1",
        'base.visible.procedure' => "CREATE PROCEDURE $q.visible_control() SELECT 1",
        'base.visible.function' => "CREATE FUNCTION $q.visible_function() RETURNS INT DETERMINISTIC NO SQL RETURN 1",
        'base.visible.event' => "CREATE EVENT $q.visible_control ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY ON COMPLETION PRESERVE DISABLE DO SET @rrns_visible=1",
        'base.visible.index' => "CREATE INDEX visible_control_index ON $q.foreign_target(visible_value)",
        'base.visible.check' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT visible_control_check CHECK(v>=0)",
        'base.visible.fk' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT visible_control_fk FOREIGN KEY(parent_id) REFERENCES $q.foreign_parent(id)",
        'connect.charset' => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        'connect.bounds' => 'SET SESSION lock_wait_timeout=8, innodb_lock_wait_timeout=2',
        'connect.timezone' => "SET time_zone='+00:00'",
        'connect.strict' => "SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        'connect.selected' => "USE $q",
        'primary.temp' => "CREATE TEMPORARY TABLE $q.customer_accounts_insert_v2(id INT PRIMARY KEY) ENGINE=InnoDB",
        'primary.temp.show' => "SHOW CREATE TABLE $q.customer_accounts_insert_v2",
        'connection.id' => 'SELECT CONNECTION_ID()',
        'app.show.account' => "SHOW CREATE TABLE $q.`customer_accounts`",
        'app.show.insert' => "SHOW CREATE TABLE $q.`customer_accounts_insert`",
        'app.show.insert_v2' => "SHOW CREATE TABLE $q.`customer_accounts_insert_v2`",
        'app.show.update' => "SHOW CREATE TABLE $q.`customer_accounts_update`",
        'app.show.delete' => "SHOW CREATE TABLE $q.`customer_accounts_delete`",
        'app.primitive' => 'FIXED_REQUIRE_CALL AccountAdmissionGuards::assertUnpinnedNamespace(PDO)',
        'context' => 'SELECT VERSION() version,@@server_uuid server_uuid,@@hostname server_host,CURRENT_USER() principal,USER() user,CURRENT_ROLE() roles,DATABASE() selected_db,@@SESSION.transaction_isolation isolation_level,@@SESSION.autocommit autocommit,@@SESSION.time_zone time_zone,@@SESSION.sql_select_limit select_limit,@@SESSION.foreign_key_checks foreign_key_checks,@@SESSION.sql_mode sql_mode,@@SESSION.character_set_client charset_client,@@SESSION.collation_connection collation_connection,@@lower_case_table_names lower_case_table_names,CONNECTION_ID() connection_id',
        'thread' => 'SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=CONNECTION_ID()',
        'begin' => 'START TRANSACTION',
        'anchor' => "SELECT COUNT(*) count FROM $q.snapshot_anchor",
        'pin' => "SELECT id FROM $q.customer_accounts LIMIT 0",
        'savepoint' => 'SAVEPOINT rrns_witness',
        'release' => 'RELEASE SAVEPOINT rrns_witness',
        'in_transaction' => 'SELECT @@SESSION.transaction_isolation isolation_level',
        'rollback' => 'ROLLBACK',
        'warnings' => 'SHOW WARNINGS',
        'warning.clean.before' => 'SELECT 1 AS rrns_warning_clean_before',
        'warning.positive' => "SELECT CAST('rrns_warning_positive_v1' AS UNSIGNED) AS rrns_warning_value",
        'warning.clean.after' => 'SELECT 1 AS rrns_warning_clean_after',
        'warning.missing' => "SELECT * FROM $q.rrns_warning_missing_control",
        'questions' => "SHOW SESSION STATUS LIKE 'Questions'",
        'publish.table' => "CREATE TABLE $q.customer_accounts_insert(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.case' => "CREATE TABLE $q.CUSTOMER_ACCOUNTS_UPDATE(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.accent' => "CREATE TABLE $q.`custómer_accounts_insert`(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.pad' => "CREATE TABLE $q.`customer_accounts_insert `(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.view' => "CREATE VIEW $q.customer_accounts_delete AS SELECT id FROM $q.snapshot_anchor",
        'publish.trigger' => "CREATE TRIGGER $q.customer_accounts_update AFTER INSERT ON $q.foreign_target FOR EACH ROW SET @rrns_value=1",
        'publish.procedure' => "CREATE PROCEDURE $q.customer_accounts_insert_v2() SELECT 1",
        'publish.function' => "CREATE FUNCTION $q.customer_accounts_delete() RETURNS INT DETERMINISTIC NO SQL RETURN 1",
        'publish.event' => "CREATE EVENT $q.customer_accounts ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY ON COMPLETION PRESERVE DISABLE DO SET @rrns_value=1",
        'publish.index' => "CREATE INDEX customer_accounts_insert ON $q.foreign_target(reserved_value)",
        'publish.check' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT customer_accounts_insert_v2 CHECK(v>=0)",
        'publish.fk' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT customer_accounts_update FOREIGN KEY(parent_id) REFERENCES $q.foreign_parent(id)",
        'publish.unique' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT customer_accounts UNIQUE(unique_value)",
        'publish.peer.table' => "CREATE TABLE $peer.customer_accounts_insert(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.peer.own' => "CREATE TRIGGER $peer.peer_own_anchor AFTER INSERT ON $peer.foreign_target FOR EACH ROW SET @rrns_peer=(SELECT COUNT(*) FROM snapshot_anchor)",
        'publish.peer.qualified' => "CREATE TRIGGER $peer.peer_qualified_anchor AFTER INSERT ON $peer.foreign_target FOR EACH ROW SET @rrns_peer_qualified=(SELECT COUNT(*) FROM $peer.snapshot_anchor)",
        'publish.peer.target' => "CREATE TRIGGER $peer.peer_target_anchor AFTER INSERT ON $peer.foreign_target FOR EACH ROW SET @rrns_target=(SELECT COUNT(*) FROM $q.snapshot_anchor)",
        'publish.anchor' => "INSERT INTO $q.snapshot_anchor VALUES(2)",
        'relation.inventory' => "SELECT TABLE_NAME,TABLE_TYPE,TABLE_NAME IN ('customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete') AS reserved_match,TABLE_NAME='customer_accounts_update' AS case_match,TABLE_NAME='customer_accounts_insert' AS accent_match,TABLE_NAME='customer_accounts_insert ' AS pad_match FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND BINARY TABLE_NAME IN (BINARY 'customer_accounts_insert',BINARY 'customer_accounts_delete',BINARY 'CUSTOMER_ACCOUNTS_UPDATE',BINARY 'custómer_accounts_insert')",
        'relation.case' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='customer_accounts_update'",
        'relation.accent' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='customer_accounts_insert'",
        'relation.pad' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='customer_accounts_insert '",
        'visible.controls' => "SELECT 'tables' kind,TABLE_NAME name FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='visible_control' UNION ALL SELECT 'triggers',TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$s' AND TRIGGER_NAME='visible_control' UNION ALL SELECT 'routines',ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$s' AND ROUTINE_NAME IN ('visible_control','visible_function') UNION ALL SELECT 'events',EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$s' AND EVENT_NAME='visible_control' UNION ALL SELECT 'indexes',INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$s' AND INDEX_NAME='visible_control_index' UNION ALL SELECT 'constraints',CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='$s' AND CONSTRAINT_NAME IN ('visible_control_check','visible_control_fk')",
        'dictionary.collations' => "SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND ((TABLE_NAME='TABLES' AND COLUMN_NAME='TABLE_NAME') OR (TABLE_NAME='TRIGGERS' AND COLUMN_NAME='TRIGGER_NAME') OR (TABLE_NAME='ROUTINES' AND COLUMN_NAME='ROUTINE_NAME') OR (TABLE_NAME='EVENTS' AND COLUMN_NAME='EVENT_NAME') OR (TABLE_NAME='STATISTICS' AND COLUMN_NAME='INDEX_NAME') OR (TABLE_NAME='TABLE_CONSTRAINTS' AND COLUMN_NAME='CONSTRAINT_NAME'))",
        'pfs.instruments' => "SELECT NAME,ENABLED,TIMED FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl'",
        'pfs.consumers' => "SELECT NAME,ENABLED FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_statements_current')",
    ];
    foreach (catalog($s) as $kind => $query) {
        $base['plain.'.$kind] = $query;
    }

    return $base;
}
function emit(array $event): void
{
    echo json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    flush();
}
function ordered(array $rows): array
{
    usort($rows, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

    return $rows;
}
function manifest(string $path): array
{
    $m = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (PHP_VERSION !== '8.4.27' || ! extension_loaded('pdo_mysql') || ! function_exists('proc_open') || ! preg_match('/\A[a-f0-9]{40}\z/', $m['expected_sha'] ?? '')
        || dirname($path) !== '/workspace/account-guard-evidence-20261101/'.$m['run_name'] || ! preg_match('/\Aacmeta-[0-9TZ-]+-[a-f0-9]{12}\z/', $m['run_name'])
        || ! str_ends_with($m['run_name'], '-'.$m['token']) || schemas($m['token']) !== $m['schemas'] || ! filter_var($m['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        throw new RuntimeException('Preflight manifest/runtime rejected before SQL');
    }
    $keys = array_keys($m['source_hashes']);
    sort($keys);
    if ($keys !== ['probe.php', 'run.py'] || ($m['server'] ?? null) !== 'va-account-guard-mysql-20261101' || ($m['configuration']['memory'] ?? null) !== 2147483648 || ($m['configuration']['swap'] ?? null) !== 2147483648) {
        throw new RuntimeException('Owned source/instance manifest rejected before SQL');
    }
    if (($m['primitive_hash'] ?? null) !== hash_file('sha256', '/workspace/VA-Studio-account-guard-v2/app/Domain/Customers/ProductionIdentity/AccountAdmissionGuards.php')) {
        throw new RuntimeException('Frozen app primitive moved before SQL');
    }
    foreach ($m['source_hashes'] as $file => $hash) {
        if (! in_array($file, ['probe.php', 'run.py'], true) || hash_file('sha256', __DIR__.'/'.$file) !== $hash) {
            throw new RuntimeException('Source hash moved');
        }
    }

    return $m;
}
function connect(array $m, bool $selected): PDO
{
    $pdo = new CompanionCountingPDO('mysql:host='.$m['ip'].';port=3306;charset=utf8mb4'.($selected ? ';dbname='.$m['schemas'][0] : ''), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
    $sql = specs($m);
    foreach (['connect.charset', 'connect.bounds', 'connect.timezone', 'connect.strict'] as $id) {
        $pdo->exec($sql[$id]);
        if (directWarnings($pdo)['rows'] !== []) {
            throw new RuntimeException('Canonical setup warning: '.$id);
        }
    }

    return $pdo;
}
function publicationWaitAllowed(string $role, string $id): bool
{
    return $role === 'publisher' && preg_match('/\A(?:create|base|publish)\./', $id) === 1 && array_key_exists($id, specs(['token' => '012345abcdef']));
}
function companionOperation(string $id): bool
{
    return in_array($id, ['context', 'questions', 'warnings', 'app.primitive', 'app.show.account', 'app.show.insert', 'app.show.insert_v2', 'app.show.update', 'app.show.delete', 'warning.clean.before', 'warning.positive', 'warning.clean.after', 'warning.missing', 'visible.controls', 'dictionary.collations', 'relation.case', 'relation.accent', 'relation.pad', 'relation.inventory'], true)
        || in_array($id, array_map(fn ($kind) => 'plain.'.$kind, array_keys(catalog('fixed'))), true);
}
function worker(array $m, string $role): never
{
    $allowed = ['publisher', 'primary', 'companion'];
    if (! in_array($role, $allowed, true)) {
        throw new RuntimeException('Unknown owned cell');
    }
    $connectionBegan = hrtime(true);
    $pdo = connect($m, $role !== 'publisher');
    $connectionSeconds = (hrtime(true) - $connectionBegan) / 1e9;
    $sql = specs($m);
    if ($role === 'companion') {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RRNSPreparedStatement::class, [$role]]);
    }
    try {
        $thread = (int) $pdo->query($sql['thread'])->fetchColumn();
        $threadError = null;
    } catch (PDOException $e) {
        $thread = null;
        $threadError = $e->errorInfo;
    }
    $ready = ['event' => 'ready', 'role' => $role, 'pid' => getmypid(), 'connection_id' => (int) $pdo->query($sql['connection.id'])->fetchColumn(), 'thread_id' => $thread, 'pfs_error' => $threadError, 'connection_setup_seconds' => $connectionSeconds, 'sql_api_calls_total' => $pdo->sqlCalls];
    file_put_contents('/workspace/account-guard-evidence-20261101/'.$m['run_name'].'/worker-'.$role.'-ready.json', json_encode($ready, JSON_THROW_ON_ERROR));
    emit($ready);
    while (($line = fgets(STDIN)) !== false) {
        $request = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        $id = $request['id'] ?? '';
        if ($id === 'close') {
            $count = $pdo->sqlCalls;
            $pdo = null;
            emit(['event' => 'closed', 'role' => $role, 'sql_api_calls_total' => $count]);
            exit(0);
        }
        if (! isset($sql[$id])) {
            throw new RuntimeException('No arbitrary SQL input');
        }
        if ($role === 'companion' && ! companionOperation($id)) {
            throw new RuntimeException('Companion catalog-only operation refused');
        }
        if ($role === 'primary' && ! in_array($id, ['context', 'begin', 'anchor', 'pin', 'savepoint', 'release', 'in_transaction', 'rollback', 'questions', 'warnings', 'primary.temp', 'primary.temp.show'], true)) {
            throw new RuntimeException('Primary fixed operation refused');
        }
        emit(['event' => 'start', 'role' => $role, 'id' => $id]);
        $began = hrtime(true);
        try {
            if ($id === 'warnings') {
                $diagnostic = directWarnings($pdo, static function (array $mode) use ($role, $id): void {
                    emit(['event' => 'diagnostic_mode', 'role' => $role, 'id' => $id, 'mode' => $mode]);
                });
                $result = $diagnostic + ['error' => null];
            } elseif ($id === 'app.primitive') {
                if ($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
                    throw new RuntimeException('Native primitive mode changed');
                }
                $GLOBALS['rrns_statement_id'] = $id;
                require_once '/workspace/VA-Studio-account-guard-v2/app/Domain/Customers/ProductionIdentity/AccountAdmissionGuards.php';
                try {
                    AccountAdmissionGuards::assertUnpinnedNamespace($pdo);
                    $primitiveOutcome = 'admitted';
                } catch (LogicException $refusal) {
                    $primitiveOutcome = 'refused';
                }
                $result = ['rows' => [], 'error' => null, 'primitive_outcome' => $primitiveOutcome];
            } else {
                if ($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) !== false) {
                    throw new RuntimeException('Native candidate mode changed');
                }
                $statement = $pdo->query($sql[$id]);
                $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
                if (! $statement->closeCursor()) {
                    throw new RuntimeException('Native cursor close failed');
                }
                $result = ['rows' => $rows, 'error' => null];
            }
        } catch (PDOException $e) {
            $result = ['rows' => [], 'error' => ['state' => $e->errorInfo[0] ?? $e->getCode(), 'code' => (int) ($e->errorInfo[1] ?? 0), 'message' => $e->getMessage()]];
        } catch (Throwable $e) {
            if ($id !== 'warnings') {
                throw $e;
            }
            $result = ['rows' => [], 'error' => ['state' => 'DIAGNOSTIC', 'code' => 0, 'class' => $e::class, 'message' => $e->getMessage()]];
        }
        emit(['event' => 'result', 'role' => $role, 'id' => $id, 'sql' => $sql[$id], 'seconds' => (hrtime(true) - $began) / 1e9, 'pdo_in_transaction' => $pdo->inTransaction(), 'sql_api_calls_total' => $pdo->sqlCalls] + $result);
    }
    $pdo = null;
    exit(0);
}
if (($argv[1] ?? '') === '--cleanup-self-test') {
    require __DIR__.'/cleanup_test.php';
    exit(0);
}
if (($argv[1] ?? '') === '--warning-self-test') {
    require __DIR__.'/warning_transport_test.php';
    exit(0);
}
if (($argv[1] ?? '') === '--self-test') {
    require __DIR__.'/companion_test.php';
    require __DIR__.'/relation_test.php';
    exit(0);
}
if (($argv[1] ?? '') === '--abort') {
    $m = manifest($argv[2]);
    $directory = dirname($argv[2]);
    $out = [];
    foreach (glob($directory.'/*-ready.json') as $file) {
        $ready = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        $pid = $ready['pid'] ?? 0;
        if (! is_int($pid) || $pid <= 1 || $pid === getmypid()) {
            continue;
        }
        $cmd = @file_get_contents('/proc/'.$pid.'/cmdline');
        $owned = $cmd !== false && str_contains($cmd, __FILE__."\0") && str_contains($cmd, $argv[2]."\0");
        if ($owned) {
            posix_kill($pid, 15);
            usleep(100000);
            if (is_dir('/proc/'.$pid)) {
                posix_kill($pid, 9);
            }
        }
        $out[] = ['pid' => $pid, 'owned_command_verified' => $owned];
    }
    file_put_contents($directory.'/abort.json', json_encode($out, JSON_THROW_ON_ERROR));
    emit(['event' => 'owned_abort', 'results' => $out]);
    exit(0);
}
if (($argv[1] ?? '') === 'worker') {
    worker(manifest($argv[2]), $argv[3]);
}
if (count($argv) !== 2) {
    fwrite(STDERR, "Exact manifest required; no SQL issued\n");
    exit(2);
}
$m = manifest($argv[1]);
if (! function_exists('posix_kill')) {
    throw new RuntimeException('Owned process cleanup unavailable; no SQL issued');
}
file_put_contents(dirname($argv[1]).'/parent-ready.json', json_encode(['pid' => getmypid(), 'role' => 'parent'], JSON_THROW_ON_ERROR));
$sql = specs($m);
$dir = dirname($argv[1]);
file_put_contents($dir.'/declared-sql.json', json_encode($sql, JSON_THROW_ON_ERROR));
$cells = [];
$owned = [];
$observer = null;
$stopped = null;
$outcome = 'INCOMPLETE';
$resourceAvailable = true;
$activeArm = null;
$pending = null;
function note(array $event): void
{
    global $dir,$owned;
    $event['at_ns'] = hrtime(true);
    file_put_contents($dir.'/partial.jsonl', json_encode($event, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n", FILE_APPEND | LOCK_EX);
    file_put_contents($dir.'/partial.json.tmp', json_encode(['last_event' => $event, 'owned' => $owned], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    rename($dir.'/partial.json.tmp', $dir.'/partial.json');
    emit($event);
}
function spawnCell(string $role): void
{
    global $cells,$owned,$argv;
    $pipes = [];
    $process = proc_open([PHP_BINARY, __FILE__, 'worker', $argv[1], $role], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, __DIR__);
    if (! is_resource($process)) {
        throw new RuntimeException('Owned worker spawn failed');
    }
    $spawnStatus = proc_get_status($process);
    file_put_contents(dirname($argv[1]).'/worker-'.$role.'-spawn.json', json_encode(['role' => $role, 'pid' => $spawnStatus['pid']], JSON_THROW_ON_ERROR));
    stream_set_blocking($pipes[1], false);
    $cells[$role] = ['process' => $process, 'pipes' => $pipes, 'buffer' => ''];
    $ready = receive($role, 'ready');
    $owned[$role] = $ready;
    if (($ready['thread_id'] ?? null) === null || ($ready['pfs_error'] ?? null) !== null) {
        throw new RuntimeException('Owned worker PFS identity unavailable');
    }
    note($ready);
}
function lines(string $role): array
{
    global $cells;
    $cell = &$cells[$role];
    $bytes = stream_get_contents($cell['pipes'][1]);
    if ($bytes !== false) {
        $cell['buffer'] .= $bytes;
    }
    $lines = [];
    while (($position = strpos($cell['buffer'], "\n")) !== false) {
        $line = substr($cell['buffer'], 0, $position);
        $cell['buffer'] = substr($cell['buffer'], $position + 1);
        $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ($decoded['role'] ?? null) !== $role) {
            throw new RuntimeException('Worker transport attribution failed');
        }
        $lines[] = $decoded;
    }

    return $lines;
}
function resources(): array
{
    global $observer,$owned,$resourceAvailable,$pending;
    if (! $observer instanceof PDO) {
        return [];
    }
    $ids = array_values(array_filter(array_column($owned, 'thread_id'), fn ($id) => is_int($id) && $id > 0));
    if ($ids === []) {
        $resourceAvailable = false;

        return ['unavailable' => 'No owned PFS thread identities'];
    }
    $list = implode(',', $ids);
    try {
        $queries['active'] = "SELECT THREAD_ID,EVENT_ID,END_EVENT_ID,NESTING_EVENT_ID,EVENT_NAME,SQL_TEXT FROM performance_schema.events_statements_current WHERE THREAD_ID IN ($list)";
        $active = $observer->query($queries['active'])->fetchAll(PDO::FETCH_ASSOC);
        $candidateThread = $pending !== null ? ($owned[$pending['role']]['thread_id'] ?? null) : null;
        $inflight = array_values(array_filter($active, fn ($row) => $row['END_EVENT_ID'] === null && $row['THREAD_ID'] !== ($owned['observer']['thread_id'] ?? null) && ($candidateThread === null || $row['THREAD_ID'] === $candidateThread)));
        $queries['metadata'] = "SELECT OBJECT_TYPE,OBJECT_SCHEMA,OBJECT_NAME,LOCK_TYPE,LOCK_DURATION,LOCK_STATUS,OWNER_THREAD_ID FROM performance_schema.metadata_locks WHERE OWNER_THREAD_ID IN ($list)";
        $metadata = $observer->query($queries['metadata'])->fetchAll(PDO::FETCH_ASSOC);
        $queries['data'] = "SELECT THREAD_ID,OBJECT_SCHEMA,OBJECT_NAME,INDEX_NAME,LOCK_TYPE,LOCK_MODE,LOCK_STATUS,LOCK_DATA FROM performance_schema.data_locks WHERE THREAD_ID IN ($list)";
        $data = $observer->query($queries['data'])->fetchAll(PDO::FETCH_ASSOC);
        $queries['waits'] = "SELECT w.* FROM performance_schema.data_lock_waits w LEFT JOIN performance_schema.data_locks r ON r.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID LEFT JOIN performance_schema.data_locks b ON b.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE r.THREAD_ID IN ($list) OR b.THREAD_ID IN ($list)";
        $waits = $observer->query($queries['waits'])->fetchAll(PDO::FETCH_ASSOC);

        return compact('queries', 'active', 'inflight', 'metadata', 'data', 'waits');
    } catch (PDOException $e) {
        $resourceAvailable = false;

        return ['unavailable' => $e->errorInfo];
    }
}
function receive(string $role, string $event, ?float $outerDeadline = null): array
{
    global $cells,$dir;
    $deadline = min(microtime(true) + 8, $outerDeadline ?? INF);
    $samples = [];
    $started = false;
    while (microtime(true) < $deadline) {
        if (is_file($dir.'/cancel')) {
            throw new RuntimeException('Host cancelled ordered transport');
        }
        foreach (lines($role) as $line) {
            if (in_array($line['event'] ?? null, ['prepared_sql', 'diagnostic_mode'], true)) {
                note($line);

                continue;
            }
            if (($line['event'] ?? null) === 'start') {
                $started = true;

                continue;
            }
            if (($line['event'] ?? null) === $event) {
                if ($samples !== []) {
                    note(['event' => 'inflight_samples', 'role' => $role, 'samples' => $samples]);
                }

                return $line;
            }
            note(['event' => 'unexpected_worker_event', 'payload' => $line]);
            throw new RuntimeException('Unexpected worker protocol');
        }
        if ($started) {
            $sample = resources();
            if (isset($sample['unavailable'])) {
                note(['event' => 'RESOURCE_OBSERVATION_UNAVAILABLE', 'role' => $role, 'resources' => $sample]);
                throw new RuntimeException('Resource observation unavailable');
            }
            if (count($samples) < 24) {
                $samples[] = $sample;
            }
            if (($sample['waits'] ?? []) !== [] || array_filter($sample['metadata'] ?? [], fn ($r) => $r['LOCK_STATUS'] === 'PENDING')) {
                $publication = publicationWaitAllowed($role, $GLOBALS['pending']['id'] ?? '');
                note(['event' => $publication ? 'PUBLISHER_PENDING_OBSERVED' : 'FIRST_UNEXPECTED_WAIT', 'role' => $role, 'operation' => $GLOBALS['pending']['id'] ?? null, 'resources' => $sample, 'candidate_acceptance' => false]);
                if (! $publication) {
                    throw new RuntimeException('Unexpected actual candidate wait; retained first resources');
                }
            }
        }
        $state = proc_get_status($cells[$role]['process']);
        if (! $state['running']) {
            throw new RuntimeException('Owned worker EOF before '.$event.' (exit '.$state['exitcode'].')');
        }
        usleep(5000);
    }
    note(['event' => 'FIRST_TIMEOUT', 'role' => $role, 'resources' => resources()]);
    throw new RuntimeException('Owned statement/transport deadline; no retry');
}
function request(string $role, string $id, ?float $outerDeadline = null): array
{
    global $cells,$pending,$sql;
    note(['event' => 'statement_requested', 'role' => $role, 'id' => $id, 'sql' => $id === 'close' ? null : ($sql[$id] ?? null)]);
    $pending = ['role' => $role, 'id' => $id];
    fwrite($cells[$role]['pipes'][0], json_encode(['id' => $id], JSON_THROW_ON_ERROR)."\n");
    fflush($cells[$role]['pipes'][0]);
    $result = receive($role, $id === 'close' ? 'closed' : 'result', $outerDeadline);
    $pending = null;
    if ($id !== 'close' && ($result['id'] ?? null) !== $id) {
        throw new RuntimeException('Statement result identity mismatch');
    }
    note($result);
    if ($id === 'close' && isset($result['sql_api_calls_total'])) {
        $GLOBALS['closed_sql_counts'][$role] = $result['sql_api_calls_total'];
    }

    return $result;
}
function must(string $role, string $id): array
{
    $result = request($role, $id);
    if (($result['error'] ?? null) !== null) {
        throw new RuntimeException('Unexpected SQL error at '.$role.'/'.$id);
    }

    return $result['rows'] ?? [];
}
function witness(string $role): void
{
    $anchor = must($role, 'anchor');
    $tx = request($role, 'in_transaction');
    if (($anchor[0]['count'] ?? null) !== 1 || ($tx['error'] ?? null) !== null || ($tx['pdo_in_transaction'] ?? false) !== true || ($tx['rows'][0]['isolation_level'] ?? null) !== 'REPEATABLE-READ') {
        throw new RuntimeException('Old RR data view/transaction/isolation lost');
    }
    must($role, 'release');
    must($role, 'savepoint');
    note(['event' => 'witness_preserved', 'role' => $role]);
}
function cleanupObservation(array $sample, float $remaining): string
{
    foreach (['sessions', 'transactions', 'metadata_locks', 'data_locks'] as $key) {
        if (! isset($sample[$key]) || ! is_array($sample[$key])) {
            throw new RuntimeException('Cleanup observation unavailable');
        }
    }
    if ($sample['sessions'] !== [] || $sample['metadata_locks'] !== [] || $sample['data_locks'] !== []) {
        throw new RuntimeException('Finished client session/locks remain after close');
    }
    if ($remaining <= 0) {
        throw new RuntimeException('Cleanup observation deadline');
    }
    if ($sample['transactions'] === []) {
        return 'clean';
    }
    if ($remaining <= 0.150) {
        throw new RuntimeException('Cleanup transaction observation did not settle');
    }

    return 'settle';
}
function released(string $role): void
{
    global $observer,$owned,$resourceAvailable,$cells;
    $deadline = microtime(true) + 1.0;
    $id = $owned[$role]['connection_id'];
    $thread = $owned[$role]['thread_id'];
    if (! $resourceAvailable || ! is_int($id) || $id <= 0 || ! is_int($thread) || $thread <= 0) {
        throw new RuntimeException('Cleanup identity/instrumentation unavailable');
    }
    request($role, 'close', $deadline);
    do {
        $status = proc_get_status($cells[$role]['process']);
        if (! $status['running']) {
            if ($status['exitcode'] !== 0) {
                throw new RuntimeException('Finished client exit not successful');
            }
            break;
        }
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Finished client exit deadline');
        }
        usleep(5000);
    } while (true);
    note(['event' => 'finished_client_exit', 'role' => $role, 'connection_id' => $id, 'thread_id' => $thread, 'status' => $status]);
    $sample = 0;
    do {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Cleanup observation deadline');
        }
        $sessions = $observer->query('SELECT THREAD_ID,PROCESSLIST_ID FROM performance_schema.threads WHERE THREAD_ID='.(int) $thread.' OR PROCESSLIST_ID='.(int) $id)->fetchAll(PDO::FETCH_ASSOC);
        $transactions = $observer->query('SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int) $id)->fetchAll(PDO::FETCH_ASSOC);
        $locks = $observer->query('SELECT OBJECT_TYPE,OBJECT_SCHEMA,OBJECT_NAME,LOCK_STATUS FROM performance_schema.metadata_locks WHERE OWNER_THREAD_ID='.(int) $thread)->fetchAll(PDO::FETCH_ASSOC);
        $data = $observer->query('SELECT THREAD_ID,OBJECT_SCHEMA,OBJECT_NAME,LOCK_STATUS FROM performance_schema.data_locks WHERE THREAD_ID='.(int) $thread)->fetchAll(PDO::FETCH_ASSOC);
        note(['event' => 'arm_cleanup_sample', 'role' => $role, 'sample' => $sample++, 'sessions' => $sessions, 'transactions' => $transactions, 'metadata_locks' => $locks, 'data_locks' => $data, 'remaining_seconds' => $deadline - microtime(true)]);
        $disposition = cleanupObservation(['sessions' => $sessions, 'transactions' => $transactions, 'metadata_locks' => $locks, 'data_locks' => $data], $deadline - microtime(true));
        if ($disposition === 'clean') {
            note(['event' => 'arm_release_verified', 'role' => $role, 'transactions' => [], 'metadata_locks' => [], 'data_locks' => [], 'sessions' => []]);

            return;
        }
        // Only transaction-catalog residue may settle; no candidate/rollback retry.
        usleep(150000);
    } while (true);
}
function finishArm(string $role): void
{
    witness($role);
    must($role, 'rollback');
    released($role);
}
function cleanCommand(string $role, string $id): array
{
    $result = request($role, $id);
    $warnings = request($role, 'warnings');
    if ($result['error'] !== null || $warnings['error'] !== null || $warnings['rows'] !== []) {
        throw new RuntimeException('Native operation/warnings not clean: '.$role.'.'.$id);
    }
    if ($role === 'companion' && ($result['pdo_in_transaction'] || $warnings['pdo_in_transaction'])) {
        throw new RuntimeException('Companion opened a transaction');
    }

    return $result;
}
function boundContext(string $role, array $expected): array
{
    global $m;
    $result = cleanCommand($role, 'context');
    $row = $result['rows'][0] ?? [];
    foreach (['version', 'server_uuid', 'server_host', 'principal', 'user', 'roles', 'isolation_level', 'sql_mode', 'charset_client', 'collation_connection', 'lower_case_table_names', 'autocommit', 'time_zone', 'select_limit', 'foreign_key_checks'] as $key) {
        if (! array_key_exists($key, $row) || $row[$key] !== ($expected[$key] ?? null)) {
            throw new RuntimeException('Live context binding changed: '.$role.'.'.$key);
        }
    }
    if ($row['selected_db'] !== $m['schemas'][0] || (int) $row['autocommit'] !== 1 || $row['isolation_level'] !== 'REPEATABLE-READ') {
        throw new RuntimeException('Canonical selected context missing');
    }
    if (isset($GLOBALS['bound_ids'][$role]) && $row['connection_id'] !== $GLOBALS['bound_ids'][$role]) {
        throw new RuntimeException('Physical connection changed');
    }
    $GLOBALS['bound_ids'][$role] = $row['connection_id'];

    return $row;
}
function publication(string $id): void
{
    $result = request('publisher', $id);
    $warnings = request('publisher', 'warnings');
    if ($id === 'publish.pad') {
        $serverMessage = "Incorrect table name 'customer_accounts_insert '";
        $expected = ['state' => '42000', 'code' => 1103, 'message' => 'SQLSTATE[42000]: Syntax error or access violation: 1103 '.$serverMessage];
        if ($result['error'] !== $expected || $warnings['error'] !== null || $warnings['rows'] !== [['Level' => 'Error', 'Code' => 1103, 'Message' => $serverMessage]]) {
            throw new RuntimeException('PAD identifier negative control differs');
        }
        note(['event' => 'predeclared_identifier_restriction', 'id' => $id, 'error' => $expected]);

        return;
    }
    if ($result['error'] !== null || $warnings['error'] !== null || $warnings['rows'] !== []) {
        throw new RuntimeException('Publication did not complete cleanly: '.$id);
    }
    note(['event' => 'publication_completed', 'id' => $id, 'wait_does_not_prove_unblocked' => true]);
}
function primaryWitness(): void
{
    global $observer,$owned,$m,$primaryContext;
    witness('primary');
    boundContext('primary', $primaryContext);
    $locks = $observer->query('SELECT OBJECT_SCHEMA,OBJECT_NAME,LOCK_TYPE,LOCK_DURATION,LOCK_STATUS FROM performance_schema.metadata_locks WHERE OWNER_THREAD_ID='.(int) $owned['primary']['thread_id'])->fetchAll(PDO::FETCH_ASSOC);
    foreach (['customer_accounts', 'snapshot_anchor'] as $table) {
        if (! array_filter($locks, fn ($row) => $row['OBJECT_SCHEMA'] === $m['schemas'][0] && $row['OBJECT_NAME'] === $table && $row['LOCK_TYPE'] === 'SHARED_READ' && $row['LOCK_DURATION'] === 'TRANSACTION' && $row['LOCK_STATUS'] === 'GRANTED')) {
            throw new RuntimeException('Primary MDL witness missing');
        }
    }
    note(['event' => 'primary_mdl_witness', 'locks' => $locks]);
}
function assertNativeRelation(array $actual, array $expected): void
{
    if (ordered($actual) !== ordered($expected)) {
        throw new RuntimeException('Exact native dictionary relation mismatch');
    }
}
/** Exact published objects are independent positives; membership is evaluated by native SQL. */
function nativeRelationProjection(array $inventory): array
{
    $expected = ['customer_accounts_insert' => 'BASE TABLE', 'customer_accounts_delete' => 'VIEW', 'CUSTOMER_ACCOUNTS_UPDATE' => 'BASE TABLE', 'custómer_accounts_insert' => 'BASE TABLE'];
    $seen = [];
    $projection = ['reserved' => [], 'case' => [], 'accent' => [], 'pad' => []];
    foreach ($inventory as $row) {
        $name = $row['TABLE_NAME'] ?? null;
        if (! is_string($name) || ! isset($expected[$name]) || isset($seen[$name]) || ($row['TABLE_TYPE'] ?? null) !== $expected[$name] || count($row) !== 6) {
            throw new RuntimeException('Exact published object inventory missing/mistyped/duplicated');
        }
        $seen[$name] = true;
        foreach (['reserved', 'case', 'accent', 'pad'] as $kind) {
            $flag = $row[$kind.'_match'] ?? null;
            if (! in_array($flag, [0, 1], true)) {
                throw new RuntimeException('Native relation boolean unavailable');
            }
            if ($flag === 1) {
                $projection[$kind][] = $kind === 'reserved' ? ['TABLE_NAME' => $name, 'TABLE_TYPE' => $row['TABLE_TYPE']] : ['TABLE_NAME' => $name];
            }
        }
        if (in_array($name, ['customer_accounts_insert', 'customer_accounts_delete'], true) && $row['reserved_match'] !== 1) {
            throw new RuntimeException('Canonical BASE/VIEW absent from native reserved relation');
        }
    }
    if (count($seen) !== count($expected)) {
        throw new RuntimeException('Exact published alias object missing');
    }

    return $projection;
}
function catalogEpoch(int $epoch): void
{
    global $m,$sql,$primaryContext;
    boundContext('companion', $primaryContext);
    $actual = cleanCommand('companion', 'app.primitive');
    if (($actual['primitive_outcome'] ?? null) !== 'refused') {
        throw new RuntimeException('Actual prepared namespace helper missed publication');
    }
    $fresh = [];
    foreach (array_keys(catalog($m['schemas'][0])) as $kind) {
        $fresh[$kind] = ordered(cleanCommand('publisher', 'plain.'.$kind)['rows']);
        $observed = ordered(cleanCommand('companion', 'plain.'.$kind)['rows']);
        if ($observed !== $fresh[$kind]) {
            throw new RuntimeException('Companion catalog stale/misclassified: '.$kind);
        }
        if (($epoch === 1 && $kind === 'tables') || $epoch === 2) {
            if ($fresh[$kind] === []) {
                throw new RuntimeException('Fresh catalog positive missing: '.$kind);
            }
        } elseif ($fresh[$kind] !== []) {
            throw new RuntimeException('Premature namespace class publication');
        }
        foreach ($fresh[$kind] as $row) {
            foreach (['ACTION_STATEMENT', 'ROUTINE_DEFINITION', 'EVENT_DEFINITION'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] === null) {
                    throw new RuntimeException('Hidden metadata definition');
                }
            }
        }
    }
    if ($epoch === 2) {
        foreach (['CHECK', 'FOREIGN KEY', 'UNIQUE'] as $type) {
            if (! in_array($type, array_column($fresh['constraints'], 'CONSTRAINT_TYPE'), true)) {
                throw new RuntimeException('Missing constraint subtype');
            }
        }
        foreach (['PROCEDURE', 'FUNCTION'] as $type) {
            if (! in_array($type, array_column($fresh['routines'], 'ROUTINE_TYPE'), true)) {
                throw new RuntimeException('Missing routine subtype');
            }
        }
        if (count($fresh['peer_scope']) !== 3 || in_array('customer_accounts_update', array_column($fresh['indexes'], 'INDEX_NAME'), true)) {
            throw new RuntimeException('Peer/FK-only negative controls differ');
        }
        foreach ($fresh['peer_scope'] as $row) {
            $qualified = preg_match('/`'.preg_quote($m['schemas'][0], '/').'`\s*\./u', $row['ACTION_STATEMENT']) === 1;
            if ($row['TRIGGER_SCHEMA'] !== $m['schemas'][1] || $qualified !== ($row['TRIGGER_NAME'] === 'peer_target_anchor')) {
                throw new RuntimeException('Peer qualification control differs');
            }
        }
        $inventory = ordered(cleanCommand('publisher', 'relation.inventory')['rows']);
        $companionInventory = ordered(cleanCommand('companion', 'relation.inventory')['rows']);
        $projected = nativeRelationProjection($inventory);
        assertNativeRelation($companionInventory, $inventory);
        assertNativeRelation($fresh['tables'], $projected['reserved']);
        foreach (['case', 'accent', 'pad'] as $relation) {
            $expected = ordered($projected[$relation]);
            assertNativeRelation(cleanCommand('publisher', 'relation.'.$relation)['rows'], $expected);
            assertNativeRelation(cleanCommand('companion', 'relation.'.$relation)['rows'], $expected);
        }
        note(['event' => 'exact_inventory_native_relations', 'inventory' => $inventory, 'projected' => $projected]);
    }
    boundContext('companion', $primaryContext);
    primaryWitness();
    if ((cleanCommand('publisher', 'anchor')['rows'][0]['count'] ?? null) !== 2) {
        throw new RuntimeException('Published anchor not fresh');
    }
    note(['event' => 'epoch_currentness', 'epoch' => $epoch, 'fresh_catalogs' => $fresh, 'companion_id' => $GLOBALS['bound_ids']['companion'], 'runtime_acceptance' => false]);
}
try {
    $observerBegan = hrtime(true);
    $observer = connect($m, false);
    note(['event' => 'observer_connection_setup', 'seconds' => (hrtime(true) - $observerBegan) / 1e9]);
    $observerContext = $observer->query($sql['context'])->fetchAll(PDO::FETCH_ASSOC);
    $identity = $observerContext[0] ?? [];
    if (($identity['version'] ?? '') !== '8.4.11' || ($identity['server_host'] ?? '') !== $m['configuration']['hostname'] || ! str_starts_with($identity['principal'] ?? '', 'root@')) {
        throw new RuntimeException('Wrong owned server/principal');
    }
    $owned['observer'] = ['connection_id' => (int) $identity['connection_id'], 'thread_id' => (int) $observer->query($sql['thread'])->fetchColumn(), 'pid' => getmypid()];
    note(['event' => 'context', 'rows' => $observerContext]);
    foreach (['pfs.instruments', 'pfs.consumers'] as $id) {
        $rows = $observer->query($sql[$id])->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === [] || array_filter($rows, fn ($r) => $r['ENABLED'] !== 'YES')) {
            throw new RuntimeException('Resource observation unavailable');
        }
        note(['event' => 'instrumentation', 'id' => $id, 'rows' => $rows]);
    }
    spawnCell('publisher');
    $publisherInitial = cleanCommand('publisher', 'context')['rows'][0];
    foreach (['server_uuid', 'principal', 'user', 'roles'] as $key) {
        if ($publisherInitial[$key] !== $identity[$key]) {
            throw new RuntimeException('Publisher identity mismatch');
        }
    }
    if (cleanCommand('publisher', 'pre.schemas')['rows'] !== []) {
        throw new RuntimeException('Scratch ownership collision');
    }
    foreach (array_keys($sql) as $id) {
        if (str_starts_with($id, 'create.') || str_starts_with($id, 'base.')) {
            publication($id);
        }
    }
    cleanCommand('publisher', 'connect.selected');
    $primaryContext = boundContext('publisher', $identity);
    spawnCell('primary');
    boundContext('primary', $primaryContext);
    cleanCommand('primary', 'begin');
    if ((cleanCommand('primary', 'anchor')['rows'][0]['count'] ?? null) !== 1) {
        throw new RuntimeException('Primary early anchor missing');
    }
    cleanCommand('primary', 'pin');
    cleanCommand('primary', 'savepoint');
    $activeArm = 'primary';
    spawnCell('companion');
    $companionContext = boundContext('companion', $primaryContext);
    // Warning producer controls run on this SAME reusable companion before candidates.
    foreach (['warning.clean.before', 'warning.positive', 'warning.clean.after', 'warning.missing'] as $control) {
        $producer = request('companion', $control);
        $diagnostic = request('companion', 'warnings');
        $expected = [];
        if ($control === 'warning.positive') {
            $expected = [['Level' => 'Warning', 'Code' => 1292, 'Message' => "Truncated incorrect INTEGER value: 'rrns_warning_positive_v1'"]];
        }
        if ($control === 'warning.missing') {
            if (($producer['error']['state'] ?? null) !== '42S02' || ($producer['error']['code'] ?? null) !== 1146) {
                throw new RuntimeException('Warning missing control producer mismatch');
            }
            $expected = [['Level' => 'Error', 'Code' => 1146, 'Message' => "Table '".$m['schemas'][0].".rrns_warning_missing_control' doesn't exist"]];
        } elseif ($producer['error'] !== null) {
            throw new RuntimeException('Warning producer failed');
        }
        if ($diagnostic['error'] !== null || $diagnostic['rows'] !== $expected || ($diagnostic['diagnostic_mode']['before'] ?? null) !== false || ($diagnostic['diagnostic_mode']['during'] ?? null) !== true || ($diagnostic['diagnostic_mode']['after'] ?? null) !== false || ($diagnostic['diagnostic_mode']['restored'] ?? false) !== true || $producer['pdo_in_transaction'] || $diagnostic['pdo_in_transaction']) {
            throw new RuntimeException('Native warning provenance failed');
        }
        note(['event' => 'warning_control_verified', 'control' => $control, 'rows' => $diagnostic['rows'], 'mode' => $diagnostic['diagnostic_mode']]);
    }
    cleanCommand('companion', 'app.show.account');
    foreach (['app.show.insert', 'app.show.insert_v2', 'app.show.update', 'app.show.delete'] as $id) {
        $negative = request('companion', $id);
        $warning = request('companion', 'warnings');
        $name = ['app.show.insert' => 'customer_accounts_insert', 'app.show.insert_v2' => 'customer_accounts_insert_v2', 'app.show.update' => 'customer_accounts_update', 'app.show.delete' => 'customer_accounts_delete'][$id];
        $message = "Table '".$m['schemas'][0].'.'.$name."' doesn't exist";
        if (($negative['error']['state'] ?? null) !== '42S02' || ($negative['error']['code'] ?? null) !== 1146 || $warning['error'] !== null || $warning['rows'] !== [['Level' => 'Error', 'Code' => 1146, 'Message' => $message]]) {
            throw new RuntimeException('Negative SHOW priming mismatch');
        }
    }
    if ((cleanCommand('companion', 'app.primitive')['primitive_outcome'] ?? null) !== 'admitted') {
        throw new RuntimeException('Original helper negative baseline differs');
    }
    foreach (array_keys(catalog($m['schemas'][0])) as $kind) {
        if (cleanCommand('companion', 'plain.'.$kind)['rows'] !== []) {
            throw new RuntimeException('Baseline namespace not empty');
        }
    }
    cleanCommand('companion', 'dictionary.collations');
    $visible = cleanCommand('companion', 'visible.controls')['rows'];
    $kinds = array_values(array_unique(array_column($visible, 'kind')));
    sort($kinds);
    if ($kinds !== ['constraints', 'events', 'indexes', 'routines', 'tables', 'triggers']) {
        throw new RuntimeException('Known visible catalog controls incomplete');
    }
    primaryWitness();
    publication('publish.table');
    publication('publish.anchor');
    catalogEpoch(1);
    foreach (['case', 'accent', 'pad', 'view', 'trigger', 'procedure', 'function', 'event', 'index', 'check', 'fk', 'unique', 'peer.table', 'peer.own', 'peer.qualified', 'peer.target'] as $id) {
        publication('publish.'.$id);
    }
    catalogEpoch(2);
    // Session-local shadow control remains PRIMARY-only authority.
    cleanCommand('primary', 'primary.temp');
    $shown = cleanCommand('primary', 'primary.temp.show')['rows'][0]['Create Table'] ?? '';
    if (! str_starts_with($shown, 'CREATE TEMPORARY TABLE ')) {
        throw new RuntimeException('Primary temporary shadow not detected');
    }
    $hidden = request('companion', 'app.show.insert_v2');
    $hiddenWarning = request('companion', 'warnings');
    $missing = "Table '".$m['schemas'][0].".customer_accounts_insert_v2' doesn't exist";
    if (($hidden['error']['state'] ?? null) !== '42S02' || ($hidden['error']['code'] ?? null) !== 1146 || $hiddenWarning['rows'] !== [['Level' => 'Error', 'Code' => 1146, 'Message' => $missing]] || $hiddenWarning['error'] !== null) {
        throw new RuntimeException('Companion incorrectly treated as primary temporary authority');
    }
    primaryWitness();
    boundContext('companion', $primaryContext);
    foreach (['primary', 'publisher', 'companion'] as $role) {
        note(['event' => 'mechanism_questions', 'role' => $role, 'rows' => cleanCommand($role, 'questions')['rows']]);
    }
    finishArm('primary');
    $activeArm = null;
    released('companion');
    released('publisher');
    $outcome = 'COMPLETED_COMPANION_MECHANISM_ONLY';
} catch (Throwable $error) {
    $stopped = ['class' => $error::class, 'message' => $error->getMessage()];
    note(['event' => 'STOP', 'reason' => $stopped, 'resources' => resources()]);
    if ($activeArm !== null) {
        try {
            // Drain an already-issued native operation once; never reissue its SQL after STOP.
            if ($pending !== null) {
                $deadline = microtime(true) + 3;
                $completed = false;
                while (microtime(true) < $deadline && ! $completed) {
                    foreach (lines($pending['role']) as $line) {
                        note(['event' => 'post_stop_completion', 'payload' => $line]);
                        if (($line['event'] ?? null) === 'result' && ($line['id'] ?? null) === $pending['id']) {
                            $completed = true;
                        }
                    }
                    if (! $completed) {
                        usleep(10000);
                    }
                }
                if (! $completed) {
                    throw new RuntimeException('Pending operation did not settle; witness inconclusive');
                }
                $pending = null;
            }
            witness($activeArm);
        } catch (Throwable $witnessError) {
            note(['event' => 'post_stop_witness_inconclusive', 'role' => $activeArm, 'message' => $witnessError->getMessage()]);
        }
    }
} finally {
    // No schema/object deletion, on PASS or STOP. Close only actual owned PHP children.
    foreach ($cells as $role => &$cell) {
        if (! is_resource($cell['process'])) {
            continue;
        }
        $status = proc_get_status($cell['process']);
        if ($status['running']) {
            @fwrite($cell['pipes'][0], "{\"id\":\"rollback\"}\n{\"id\":\"close\"}\n");
            @fflush($cell['pipes'][0]);
        }
        @fclose($cell['pipes'][0]);
        $deadline = microtime(true) + 3;
        do {
            $status = proc_get_status($cell['process']);
            if (! $status['running']) {
                break;
            } usleep(10000);
        } while (microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($cell['process'], 15);
            usleep(100000);
            $status = proc_get_status($cell['process']);
            if ($status['running']) {
                proc_terminate($cell['process'], 9);
            }
        }
        $remaining = stream_get_contents($cell['pipes'][1]);
        if ($remaining !== false && $remaining !== '') {
            note(['event' => 'owned_close_transport', 'role' => $role, 'raw' => $remaining]);
        }
        fclose($cell['pipes'][1]);
        proc_close($cell['process']);
    }
    unset($cell);
    if ($observer instanceof CompanionCountingPDO) {
        note(['event' => 'observer_sql_api_calls_total', 'count' => $observer->sqlCalls]);
        note(['event' => 'aggregate_sql_api_counts', 'closed_roles' => $GLOBALS['closed_sql_counts'] ?? [], 'observer' => $observer->sqlCalls, 'all_four_pdos_complete' => count($GLOBALS['closed_sql_counts'] ?? []) === 3, 'total' => $observer->sqlCalls + array_sum($GLOBALS['closed_sql_counts'] ?? []), 'budgets110_15_acceptance' => false]);
    }
    $observer = null;
    file_put_contents($dir.'/owned-clients.json', json_encode($owned, JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/result.json', json_encode(['outcome' => $outcome, 'stop' => $stopped, 'retained_schemas' => $m['schemas'], 'owned' => $owned, 'resource_instrumentation_available' => $resourceAvailable, 'runtime_strategy_selected' => false, 'full_terminal_acceptance' => false, 'restricted_principal_visibility' => 'UNTESTED', 'no_sampled_wait_is_not_lock_freedom' => true], JSON_THROW_ON_ERROR));
    note(['event' => 'finished', 'outcome' => $outcome, 'stop' => $stopped, 'retained_schemas' => $m['schemas']]);
}
exit($stopped === null ? 0 : 1);
