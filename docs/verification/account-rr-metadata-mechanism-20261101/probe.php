<?php

use App\Domain\Customers\ProductionIdentity\AccountAdmissionGuards;

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
function schemas(string $token): array
{
    if (! preg_match('/\A[a-f0-9]{12}\z/', $token)) {
        throw new RuntimeException('Invalid internal token');
    }
    $selected = 'va_rrns_20261101_'.$token;

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
        'base.target' => "CREATE TABLE $q.foreign_target(id INT PRIMARY KEY,v INT,parent_id INT,KEY plain_parent_support(parent_id)) ENGINE=InnoDB",
        'base.peer.anchor' => "CREATE TABLE $peer.snapshot_anchor(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.peer.target' => "CREATE TABLE $peer.foreign_target(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.visible.table' => "CREATE TABLE $q.visible_control(id INT PRIMARY KEY) ENGINE=InnoDB",
        'base.visible.trigger' => "CREATE TRIGGER $q.visible_control AFTER INSERT ON $q.foreign_target FOR EACH ROW SET @rrns_visible=1",
        'base.visible.procedure' => "CREATE PROCEDURE $q.visible_control() SELECT 1",
        'base.visible.function' => "CREATE FUNCTION $q.visible_function() RETURNS INT DETERMINISTIC NO SQL RETURN 1",
        'base.visible.event' => "CREATE EVENT $q.visible_control ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY ON COMPLETION PRESERVE DISABLE DO SET @rrns_visible=1",
        'base.visible.index' => "CREATE INDEX visible_control_index ON $q.foreign_target(v)",
        'base.visible.check' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT visible_control_check CHECK(v>=0)",
        'base.visible.fk' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT visible_control_fk FOREIGN KEY(parent_id) REFERENCES $q.foreign_parent(id)",
        'connect.charset' => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
        'connect.bounds' => 'SET SESSION lock_wait_timeout=2, innodb_lock_wait_timeout=2',
        'connection.id' => 'SELECT CONNECTION_ID()',
        'app.show.account' => "SHOW CREATE TABLE $q.`customer_accounts`",
        'app.show.insert' => "SHOW CREATE TABLE $q.`customer_accounts_insert`",
        'app.show.insert_v2' => "SHOW CREATE TABLE $q.`customer_accounts_insert_v2`",
        'app.show.update' => "SHOW CREATE TABLE $q.`customer_accounts_update`",
        'app.show.delete' => "SHOW CREATE TABLE $q.`customer_accounts_delete`",
        'app.primitive' => 'FIXED_REQUIRE_CALL AccountAdmissionGuards::assertUnpinnedNamespace(PDO)',
        'context' => 'SELECT VERSION() version,@@server_uuid server_uuid,@@hostname server_host,CURRENT_USER() principal,USER() user,CURRENT_ROLE() roles,DATABASE() selected_db,@@SESSION.transaction_isolation isolation_level,@@SESSION.sql_mode sql_mode,@@SESSION.character_set_client charset_client,@@SESSION.collation_connection collation_connection,@@lower_case_table_names lower_case_table_names,CONNECTION_ID() connection_id',
        'thread' => 'SELECT THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=CONNECTION_ID()',
        'begin' => 'START TRANSACTION',
        'anchor' => "SELECT COUNT(*) count FROM $q.snapshot_anchor",
        'pin' => "SELECT id FROM $q.customer_accounts LIMIT 0",
        'savepoint' => 'SAVEPOINT rrns_witness',
        'release' => 'RELEASE SAVEPOINT rrns_witness',
        'in_transaction' => 'SELECT @@SESSION.transaction_isolation isolation_level',
        'rollback' => 'ROLLBACK',
        'warnings' => 'SHOW WARNINGS',
        'questions' => "SHOW SESSION STATUS LIKE 'Questions'",
        'publish.table' => "CREATE TABLE $q.customer_accounts_insert(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.case' => "CREATE TABLE $q.CUSTOMER_ACCOUNTS_UPDATE(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.accent' => "CREATE TABLE $q.`custómer_accounts_insert`(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.pad' => "CREATE TABLE $q.`customer_accounts_insert `(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.view' => "CREATE VIEW $q.customer_accounts_delete AS SELECT id FROM $q.snapshot_anchor",
        'publish.trigger' => "CREATE TRIGGER $q.customer_accounts_update AFTER INSERT ON $q.foreign_target FOR EACH ROW SET @rrns_value=1",
        'publish.procedure' => "CREATE PROCEDURE $q.customer_accounts_insert_v2() SELECT 1",
        'publish.same_function' => "CREATE FUNCTION $q.customer_accounts_insert_v2() RETURNS INT DETERMINISTIC NO SQL RETURN 1",
        'publish.function' => "CREATE FUNCTION $q.customer_accounts_delete() RETURNS INT DETERMINISTIC NO SQL RETURN 1",
        'publish.event' => "CREATE EVENT $q.customer_accounts ON SCHEDULE AT CURRENT_TIMESTAMP + INTERVAL 1 DAY ON COMPLETION PRESERVE DISABLE DO SET @rrns_value=1",
        'publish.index' => "CREATE INDEX customer_accounts_insert ON $q.foreign_target(v)",
        'publish.check' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT customer_accounts_insert_v2 CHECK(v>=0)",
        'publish.fk' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT customer_accounts_update FOREIGN KEY(parent_id) REFERENCES $q.foreign_parent(id)",
        'publish.unique' => "ALTER TABLE $q.foreign_target ADD CONSTRAINT customer_accounts UNIQUE(v)",
        'publish.peer.table' => "CREATE TABLE $peer.customer_accounts_insert(id INT PRIMARY KEY) ENGINE=InnoDB",
        'publish.peer.own' => "CREATE TRIGGER $peer.peer_own_anchor AFTER INSERT ON $peer.foreign_target FOR EACH ROW SET @rrns_peer=(SELECT COUNT(*) FROM snapshot_anchor)",
        'publish.peer.qualified' => "CREATE TRIGGER $peer.peer_qualified_anchor AFTER INSERT ON $peer.foreign_target FOR EACH ROW SET @rrns_peer_qualified=(SELECT COUNT(*) FROM $peer.snapshot_anchor)",
        'publish.peer.target' => "CREATE TRIGGER $peer.peer_target_anchor AFTER INSERT ON $peer.foreign_target FOR EACH ROW SET @rrns_target=(SELECT COUNT(*) FROM $q.snapshot_anchor)",
        'publish.anchor' => "INSERT INTO $q.snapshot_anchor VALUES(2)",
        'relation.case' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='customer_accounts_update'",
        'relation.accent' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='customer_accounts_insert'",
        'relation.pad' => "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='customer_accounts_insert '",
        'show.tables' => "SHOW FULL TABLES FROM $q WHERE ".qi('Tables_in_'.$s)." IN ('customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')",
        'show.triggers' => "SHOW TRIGGERS FROM $q WHERE `Trigger` IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')",
        'show.procedures' => "SHOW PROCEDURE STATUS WHERE Db='$s' AND Name IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')",
        'show.functions' => "SHOW FUNCTION STATUS WHERE Db='$s' AND Name IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')",
        'show.events' => "SHOW EVENTS FROM $q WHERE Name IN ('customer_accounts','customer_accounts_insert','customer_accounts_insert_v2','customer_accounts_update','customer_accounts_delete')",
        'show.create.table' => "SHOW CREATE TABLE $q.customer_accounts_insert",
        'show.create.view' => "SHOW CREATE TABLE $q.customer_accounts_delete",
        'show.create.absent' => "SHOW CREATE TABLE $q.rrns_known_absent",
        'show.known.indexes' => "SHOW INDEX FROM $q.foreign_target",
        'visible.controls' => "SELECT 'tables' kind,TABLE_NAME name FROM information_schema.TABLES WHERE TABLE_SCHEMA='$s' AND TABLE_NAME='visible_control' UNION ALL SELECT 'triggers',TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$s' AND TRIGGER_NAME='visible_control' UNION ALL SELECT 'routines',ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='$s' AND ROUTINE_NAME IN ('visible_control','visible_function') UNION ALL SELECT 'events',EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA='$s' AND EVENT_NAME='visible_control' UNION ALL SELECT 'indexes',INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$s' AND INDEX_NAME='visible_control_index' UNION ALL SELECT 'constraints',CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA='$s' AND CONSTRAINT_NAME IN ('visible_control_check','visible_control_fk')",
        'dictionary.collations' => "SELECT TABLE_NAME,COLUMN_NAME,CHARACTER_SET_NAME,COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='information_schema' AND ((TABLE_NAME='TABLES' AND COLUMN_NAME='TABLE_NAME') OR (TABLE_NAME='TRIGGERS' AND COLUMN_NAME='TRIGGER_NAME') OR (TABLE_NAME='ROUTINES' AND COLUMN_NAME='ROUTINE_NAME') OR (TABLE_NAME='EVENTS' AND COLUMN_NAME='EVENT_NAME') OR (TABLE_NAME='STATISTICS' AND COLUMN_NAME='INDEX_NAME') OR (TABLE_NAME='TABLE_CONSTRAINTS' AND COLUMN_NAME='CONSTRAINT_NAME'))",
        'pfs.instruments' => "SELECT NAME,ENABLED,TIMED FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl'",
        'pfs.consumers' => "SELECT NAME,ENABLED FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_statements_current')",
    ];
    foreach (['plain' => '', 'share' => ' FOR SHARE', 'update' => ' FOR UPDATE'] as $arm => $lock) {
        foreach (catalog($s, $lock) as $kind => $sql) {
            $base["$arm.$kind"] = $sql;
        }
        if ($lock !== '') {
            $branches = [];
            foreach (array_slice(catalog($s), 0, 6, true) as $sql) {
                $branches[] = '('.preg_replace('/\ASELECT .*? FROM /s', 'SELECT 1 FROM ', $sql).$lock.')';
            }
            $base[$arm.'.union'] = implode(' UNION ALL ', $branches);
        }
    }
    foreach (['share', 'share_union', 'update', 'update_union', 'show'] as $arm) {
        $base['control.ddl.'.$arm] = "CREATE INDEX rrns_safe_$arm ON $q.foreign_target(parent_id)";
    }

    return $base;
}
function capability(string $id, array $error): bool
{
    if (! preg_match('/\A(?:share|update)\./', $id)) {
        return false;
    }
    [$state,$code] = [$error['state'], $error['code']];

    return in_array([$state, $code], [['42000', 1064], ['42000', 1235], ['HY000', 1221]], true)
        || (in_array([$state, $code], [['42000', 1044], ['42000', 1142]], true) && str_contains(strtolower($error['message']), 'information_schema'));
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
        || dirname($path) !== '/workspace/account-guard-evidence-20261101/'.$m['run_name'] || ! preg_match('/\Arrns-[0-9TZ-]+-[a-f0-9]{12}\z/', $m['run_name'])
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
    $pdo = new PDO('mysql:host='.$m['ip'].';port=3306;charset=utf8mb4'.($selected ? ';dbname='.$m['schemas'][0] : ''), 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
    $sql = specs($m);
    $pdo->exec($sql['connect.charset']);
    $pdo->exec($sql['connect.bounds']);

    return $pdo;
}
function worker(array $m, string $role): never
{
    $allowed = ['bootstrap', 'publisher', 'app_shape', 'plain', 'share', 'share_union', 'update', 'update_union', 'show'];
    if (! in_array($role, $allowed, true)) {
        throw new RuntimeException('Unknown owned cell');
    }
    $pdo = connect($m, $role !== 'bootstrap');
    $sql = specs($m);
    if ($role === 'app_shape') {
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RRNSPreparedStatement::class, [$role]]);
    }
    try {
        $thread = (int) $pdo->query($sql['thread'])->fetchColumn();
        $threadError = null;
    } catch (PDOException $e) {
        $thread = null;
        $threadError = $e->errorInfo;
    }
    $ready = ['event' => 'ready', 'role' => $role, 'pid' => getmypid(), 'connection_id' => (int) $pdo->query($sql['connection.id'])->fetchColumn(), 'thread_id' => $thread, 'pfs_error' => $threadError];
    file_put_contents('/workspace/account-guard-evidence-20261101/'.$m['run_name'].'/worker-'.$role.'-ready.json', json_encode($ready, JSON_THROW_ON_ERROR));
    emit($ready);
    while (($line = fgets(STDIN)) !== false) {
        $request = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        $id = $request['id'] ?? '';
        if ($id === 'close') {
            $pdo = null;
            emit(['event' => 'closed', 'role' => $role]);
            exit(0);
        }
        if (! isset($sql[$id])) {
            throw new RuntimeException('No arbitrary SQL input');
        }
        emit(['event' => 'start', 'role' => $role, 'id' => $id]);
        $began = hrtime(true);
        try {
            if ($id === 'app.primitive') {
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
                $statement = $pdo->query($sql[$id]);
                $rows = $statement->columnCount() > 0 ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
                $result = ['rows' => $rows, 'error' => null];
            }
        } catch (PDOException $e) {
            $result = ['rows' => [], 'error' => ['state' => $e->errorInfo[0] ?? $e->getCode(), 'code' => (int) ($e->errorInfo[1] ?? 0), 'message' => $e->getMessage()]];
        }
        emit(['event' => 'result', 'role' => $role, 'id' => $id, 'sql' => $sql[$id], 'seconds' => (hrtime(true) - $began) / 1e9, 'pdo_in_transaction' => $pdo->inTransaction()] + $result);
    }
    $pdo = null;
    exit(0);
}
if (($argv[1] ?? '') === '--self-test') {
    $m = ['token' => '012345abcdef'];
    $q = specs($m);
    if (count(catalog(schemas($m['token'])[0])) !== 7 || substr_count($q['share.union'], 'FOR SHARE') !== 6 || substr_count($q['update.union'], 'FOR UPDATE') !== 6
        || preg_match('/\b(?:DROP|GRANT|CREATE USER|SET GLOBAL|READ COMMITTED)\b/', implode("\n", $q)) || ! capability('share.tables', ['state' => '42000', 'code' => 1064, 'message' => 'syntax'])
        || capability('plain.tables', ['state' => '42000', 'code' => 1064, 'message' => 'syntax']) || capability('share.tables', ['state' => 'HY000', 'code' => 1205, 'message' => 'timeout'])
        || capability('share.tables', ['state' => '42000', 'code' => 1142, 'message' => 'other denied table'])
        || ! capability('share.tables', ['state' => '42000', 'code' => 1142, 'message' => 'information_schema denied'])
        || ! str_contains($q['base.target'], 'plain_parent_support') || ! str_contains($q['publish.fk'], 'customer_accounts_update')) {
        throw new RuntimeException('Pure finite SQL contract failed');
    }
    emit(['pure' => 'PASS', 'operation_ids' => count($q), 'database_connections' => 0]);
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
        $resourceAvailable = false;
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
function receive(string $role, string $event): array
{
    global $cells,$dir;
    $deadline = microtime(true) + 8;
    $samples = [];
    $started = false;
    while (microtime(true) < $deadline) {
        if (is_file($dir.'/cancel')) {
            throw new RuntimeException('Host cancelled ordered transport');
        }
        foreach (lines($role) as $line) {
            if (($line['event'] ?? null) === 'prepared_sql') {
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
            if (count($samples) < 24) {
                $samples[] = $sample;
            }
            if (($sample['waits'] ?? []) !== [] || array_filter($sample['metadata'] ?? [], fn ($r) => $r['LOCK_STATUS'] === 'PENDING')) {
                note(['event' => 'FIRST_UNEXPECTED_WAIT', 'role' => $role, 'resources' => $sample]);
                throw new RuntimeException('Unexpected actual wait; retained first resources');
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
function request(string $role, string $id): array
{
    global $cells,$pending,$sql;
    note(['event' => 'statement_requested', 'role' => $role, 'id' => $id, 'sql' => $id === 'close' ? null : ($sql[$id] ?? null)]);
    $pending = ['role' => $role, 'id' => $id];
    fwrite($cells[$role]['pipes'][0], json_encode(['id' => $id], JSON_THROW_ON_ERROR)."\n");
    fflush($cells[$role]['pipes'][0]);
    $result = receive($role, $id === 'close' ? 'closed' : 'result');
    $pending = null;
    if ($id !== 'close' && ($result['id'] ?? null) !== $id) {
        throw new RuntimeException('Statement result identity mismatch');
    }
    note($result);

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
function released(string $role): void
{
    global $observer,$owned,$resourceAvailable;
    $id = $owned[$role]['connection_id'];
    $thread = $owned[$role]['thread_id'];
    $transactions = $observer->query('SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_mysql_thread_id='.(int) $id)->fetchAll(PDO::FETCH_ASSOC);
    try {
        $locks = $thread > 0 ? $observer->query('SELECT OBJECT_TYPE,OBJECT_SCHEMA,OBJECT_NAME,LOCK_STATUS FROM performance_schema.metadata_locks WHERE OWNER_THREAD_ID='.(int) $thread)->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $error) {
        note(['event' => 'release_lock_check_inconclusive', 'role' => $role, 'error' => $error->errorInfo]);
        $resourceAvailable = false;
        $locks = [];
    }
    $data = [];
    try {
        if ($thread > 0) {
            $data = $observer->query('SELECT THREAD_ID,OBJECT_SCHEMA,OBJECT_NAME,LOCK_STATUS FROM performance_schema.data_locks WHERE THREAD_ID='.(int) $thread)->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $error) {
        $resourceAvailable = false;
        note(['event' => 'release_data_check_inconclusive', 'role' => $role, 'error' => $error->errorInfo]);
    }
    note(['event' => 'arm_release_verified', 'role' => $role, 'transactions' => $transactions, 'metadata_locks' => $locks, 'data_locks' => $data]);
    if ($transactions !== [] || $locks !== [] || $data !== []) {
        note(['event' => 'rollback_not_released', 'role' => $role, 'transactions' => $transactions, 'locks' => $locks]);
        throw new RuntimeException('Owned arm locks/transaction remain after rollback');
    }
}
function finishArm(string $role): void
{
    witness($role);
    must($role, 'rollback');
    released($role);
}
try {
    $observer = connect($m, false);
    $observerContext = $observer->query($sql['context'])->fetchAll(PDO::FETCH_ASSOC);
    if (($observerContext[0]['version'] ?? '') !== '8.4.11' || ($observerContext[0]['server_host'] ?? '') !== $m['configuration']['hostname'] || ! str_starts_with($observerContext[0]['principal'] ?? '', 'root@') || ($observerContext[0]['isolation_level'] ?? '') !== 'REPEATABLE-READ') {
        throw new RuntimeException('Wrong owned server/principal/context');
    }
    $owned['observer'] = ['connection_id' => (int) $observerContext[0]['connection_id'], 'thread_id' => (int) $observer->query($sql['thread'])->fetchColumn(), 'pid' => getmypid()];
    note(['event' => 'context', 'rows' => $observerContext]);
    foreach (['pfs.instruments', 'pfs.consumers'] as $id) {
        try {
            $rows = $observer->query($sql[$id])->fetchAll(PDO::FETCH_ASSOC);
            note(['event' => 'instrumentation', 'id' => $id, 'rows' => $rows]);
            if ($rows === [] || array_filter($rows, fn ($r) => $r['ENABLED'] !== 'YES')) {
                $resourceAvailable = false;
            }
        } catch (PDOException $e) {
            $resourceAvailable = false;
            note(['event' => 'instrumentation_unavailable', 'error' => $e->errorInfo]);
        }
    }
    spawnCell('bootstrap');
    $bootstrapContext = must('bootstrap', 'context');
    if ($bootstrapContext[0]['server_uuid'] !== $observerContext[0]['server_uuid'] || $bootstrapContext[0]['principal'] !== $observerContext[0]['principal'] || $bootstrapContext[0]['roles'] !== $observerContext[0]['roles']) {
        throw new RuntimeException('Bootstrap context differs');
    }
    must('bootstrap', 'dictionary.collations');
    if (must('bootstrap', 'pre.schemas') !== []) {
        throw new RuntimeException('Scratch ownership collision; no adoption');
    }
    foreach (array_keys($sql) as $id) {
        if (str_starts_with($id, 'create.') || str_starts_with($id, 'base.')) {
            must('bootstrap', $id);
        }
    }
    request('bootstrap', 'close');
    spawnCell('publisher');
    $publisherContext = must('publisher', 'context');
    if ($publisherContext[0]['server_uuid'] !== $observerContext[0]['server_uuid'] || $publisherContext[0]['principal'] !== $observerContext[0]['principal'] || $publisherContext[0]['roles'] !== $observerContext[0]['roles']) {
        throw new RuntimeException('Publisher context differs');
    }
    $arms = ['plain', 'share', 'share_union', 'update', 'update_union', 'show'];
    foreach ($arms as $arm) {
        spawnCell($arm);
        $ctx = must($arm, 'context');
        if ($ctx[0]['server_uuid'] !== $observerContext[0]['server_uuid'] || $ctx[0]['principal'] !== $observerContext[0]['principal'] || $ctx[0]['roles'] !== $observerContext[0]['roles'] || $ctx[0]['isolation_level'] !== 'REPEATABLE-READ') {
            throw new RuntimeException('Reader principal/role/isolation mismatch');
        }
        must($arm, 'begin');
        must($arm, 'anchor');
        must($arm, 'pin');
        must($arm, 'savepoint');
        $visible = must($arm, 'visible.controls');
        $kinds = array_values(array_unique(array_column($visible, 'kind')));
        sort($kinds);
        if ($kinds !== ['constraints', 'events', 'indexes', 'routines', 'tables', 'triggers']) {
            throw new RuntimeException('Preexisting visible controls incomplete');
        }
        foreach (array_keys(catalog($m['schemas'][0])) as $kind) {
            if (must($arm, 'plain.'.$kind) !== []) {
                throw new RuntimeException('Initial namespace baseline not absent');
            }
        }
        witness($arm);
    }
    spawnCell('app_shape');
    $appContext = must('app_shape', 'context');
    if ($appContext[0]['server_uuid'] !== $observerContext[0]['server_uuid'] || $appContext[0]['principal'] !== $observerContext[0]['principal'] || $appContext[0]['roles'] !== $observerContext[0]['roles'] || $appContext[0]['isolation_level'] !== 'REPEATABLE-READ') {
        throw new RuntimeException('App-shaped cell context mismatch');
    }
    must('app_shape', 'begin');
    must('app_shape', 'anchor');
    must('app_shape', 'pin');
    must('app_shape', 'savepoint');
    must('app_shape', 'app.show.account');
    foreach (['app.show.insert', 'app.show.insert_v2', 'app.show.update', 'app.show.delete'] as $id) {
        $negative = request('app_shape', $id);
        if (($negative['error']['state'] ?? null) !== '42S02' || ($negative['error']['code'] ?? null) !== 1146) {
            throw new RuntimeException('Original negative SHOW priming did not prove expected absence');
        }
    }
    $before = request('app_shape', 'app.primitive');
    if ($before['error'] !== null || ($before['primitive_outcome'] ?? null) !== 'admitted') {
        throw new RuntimeException('App-shaped absent namespace baseline failed');
    }
    $activeArm = 'app_shape';
    must('publisher', 'publish.table');
    must('publisher', 'publish.anchor');
    // The original helper executes BEFORE a fresh metadata lookup can warm the publication path.
    $after = request('app_shape', 'app.primitive');
    if ($after['error'] !== null) {
        throw new RuntimeException('Actual original prepared helper query failed unexpectedly');
    }
    $tableOnly = must('publisher', 'plain.tables');
    if (! in_array('customer_accounts_insert', array_column($tableOnly, 'TABLE_NAME'), true)) {
        throw new RuntimeException('Table-only fresh positive missing');
    }
    foreach (['triggers', 'routines', 'events', 'indexes', 'constraints', 'peer_scope'] as $kind) {
        if (must('publisher', 'plain.'.$kind) !== []) {
            throw new RuntimeException('Other namespace class was published before table-only cell');
        }
    }
    witness('app_shape');
    must('app_shape', 'rollback');
    released('app_shape');
    $activeArm = null;
    note(['event' => 'app_shape_contrast', 'before' => $before['primitive_outcome'], 'after' => $after['primitive_outcome'], 'framework_failure' => 'admitted_reserved_table']);
    if (($after['primitive_outcome'] ?? null) === 'refused') {
        throw new RuntimeException('ROOT_CAUSE_CONTRAST: raw actual helper current while framework memo failed; stop before mechanism claims');
    }
    if (($after['primitive_outcome'] ?? null) !== 'admitted') {
        throw new RuntimeException('Unknown app-shaped outcome');
    }
    foreach (array_keys($sql) as $id) {
        if (! str_starts_with($id, 'publish.') || in_array($id, ['publish.table', 'publish.anchor'], true)) {
            continue;
        }
        $result = request('publisher', $id);
        must('publisher', 'warnings');
        if ($result['error'] !== null) {
            $known = ($id === 'publish.pad' && [$result['error']['state'], $result['error']['code']] === ['42000', 1103])
                || ($id === 'publish.same_function' && [$result['error']['state'], $result['error']['code']] === ['42000', 1304]);
            if (! $known) {
                throw new RuntimeException('Unexpected publication error; retain state');
            }
            note(['event' => 'predeclared_identifier_restriction', 'id' => $id, 'error' => $result['error']]);
        }
    }
    if ((must('publisher', 'anchor')[0]['count'] ?? null) !== 2) {
        throw new RuntimeException('Fresh anchor publication missing');
    }
    $fresh = [];
    foreach (array_keys(catalog($m['schemas'][0])) as $kind) {
        $fresh[$kind] = ordered(must('publisher', 'plain.'.$kind));
        if ($fresh[$kind] === []) {
            throw new RuntimeException('Fresh positive catalog missing: '.$kind);
        }
        foreach ($fresh[$kind] as $row) {
            foreach (['ACTION_STATEMENT', 'ROUTINE_DEFINITION', 'EVENT_DEFINITION'] as $field) {
                if (array_key_exists($field, $row) && $row[$field] === null) {
                    throw new RuntimeException('Inaccessible metadata definition');
                }
            }
        }
    }
    $checks = array_column($fresh['constraints'], 'CONSTRAINT_TYPE');
    foreach (['CHECK', 'FOREIGN KEY', 'UNIQUE'] as $type) {
        if (! in_array($type, $checks, true)) {
            throw new RuntimeException('Fresh constraint subtype missing');
        }
    }
    foreach (['PROCEDURE', 'FUNCTION'] as $type) {
        if (! in_array($type, array_column($fresh['routines'], 'ROUTINE_TYPE'), true)) {
            throw new RuntimeException('Fresh routine subtype missing');
        }
    }
    if (in_array('customer_accounts_update', array_column($fresh['indexes'], 'INDEX_NAME'), true)) {
        throw new RuntimeException('FK-only oracle masked by implicit reserved index');
    }
    if (count($fresh['peer_scope']) !== 3) {
        throw new RuntimeException('Fresh peer controls missing');
    }
    foreach ($fresh['peer_scope'] as $row) {
        $qualified = preg_match('/`'.preg_quote($m['schemas'][0], '/').'`\s*\./u', $row['ACTION_STATEMENT']) === 1;
        if ($row['TRIGGER_SCHEMA'] !== $m['schemas'][1] || $qualified !== ($row['TRIGGER_NAME'] === 'peer_target_anchor')) {
            throw new RuntimeException('Peer versus selected qualifier control failed');
        }
    }
    foreach (['relation.case', 'relation.accent', 'relation.pad'] as $id) {
        must('publisher', $id);
    }
    note(['event' => 'fresh_oracles', 'catalogs' => $fresh]);
    foreach ($arms as $arm) {
        $activeArm = $arm;
        $start = must($arm, 'questions');
        $unsupported = false;
        $ids = $arm === 'show' ? array_values(array_filter(array_keys($sql), fn ($id) => str_starts_with($id, 'show.')))
            : (str_ends_with($arm, '_union') ? [str_replace('_union', '.union', $arm)] : array_map(fn ($kind) => $arm.'.'.$kind, array_keys(catalog($m['schemas'][0]))));
        foreach ($ids as $id) {
            $result = request($arm, $id);
            $warnings = must($arm, 'warnings');
            if ($result['error'] !== null) {
                if ($id === 'show.create.absent' && [$result['error']['state'], $result['error']['code']] === ['42S02', 1146]) {
                    continue;
                }
                if (capability($id, $result['error'])) {
                    $unsupported = true;
                    note(['event' => 'unsupported_family', 'role' => $arm, 'id' => $id, 'error' => $result['error']]);
                    break;
                }
                throw new RuntimeException('Unknown candidate error: '.$id);
            }
            if ($warnings !== []) {
                throw new RuntimeException('Candidate warnings require review, not qualification');
            }
            if ($arm === 'plain') {
                note(['event' => 'plain_currentness_observation', 'kind' => substr($id, 6), 'equals_fresh' => ordered($result['rows']) === $fresh[substr($id, 6)]]);

                continue;
            }
            if (str_ends_with($arm, '_union')) {
                $expected = array_sum(array_map('count', array_slice($fresh, 0, 6, true)));
                if (count($result['rows']) !== $expected) {
                    throw new RuntimeException('Combined candidate missed a fresh positive under early RR');
                }
            } elseif ($arm !== 'show') {
                if (ordered($result['rows']) !== $fresh[substr($id, strpos($id, '.') + 1)]) {
                    throw new RuntimeException('Candidate catalog stale/misclassified under early RR: '.$id);
                }
            } else {
                if ($id === 'show.create.absent') {
                    throw new RuntimeException('Known absent exact object was reported present');
                }
                if ($result['rows'] === []) {
                    throw new RuntimeException('SHOW missed a fresh positive: '.$id);
                }
                // Listings are normalized below against the fresh native dictionary relation; no PHP case folding.
                if ($id === 'show.tables') {
                    $actual = array_column($result['rows'], 'Tables_in_'.$m['schemas'][0]);
                    $expected = array_column($fresh['tables'], 'TABLE_NAME');
                    sort($actual);
                    sort($expected);
                    if ($actual !== $expected) {
                        throw new RuntimeException('SHOW table comparison differs from fresh dictionary');
                    }
                } elseif ($id === 'show.triggers') {
                    $actual = array_map(fn ($r) => ['TRIGGER_NAME' => $r['Trigger'], 'EVENT_OBJECT_TABLE' => $r['Table'], 'ACTION_TIMING' => $r['Timing'], 'ACTION_STATEMENT' => $r['Statement']], $result['rows']);
                    if (ordered($actual) !== $fresh['triggers']) {
                        throw new RuntimeException('SHOW trigger comparison differs from fresh dictionary');
                    }
                } elseif (in_array($id, ['show.procedures', 'show.functions'], true)) {
                    $type = $id === 'show.procedures' ? 'PROCEDURE' : 'FUNCTION';
                    $actual = array_column($result['rows'], 'Name');
                    $expected = array_column(array_filter($fresh['routines'], fn ($r) => $r['ROUTINE_TYPE'] === $type), 'ROUTINE_NAME');
                    sort($actual);
                    sort($expected);
                    if ($actual !== $expected) {
                        throw new RuntimeException('SHOW routine comparison differs from fresh dictionary');
                    }
                } elseif ($id === 'show.events') {
                    $actual = array_map(fn ($r) => [$r['Name'], $r['Status']], $result['rows']);
                    $expected = array_map(fn ($r) => [$r['EVENT_NAME'], $r['STATUS']], $fresh['events']);
                    if (ordered($actual) !== ordered($expected)) {
                        throw new RuntimeException('SHOW event comparison differs from fresh dictionary');
                    }
                } elseif ($id === 'show.create.table' && ! isset($result['rows'][0]['Create Table'])) {
                    throw new RuntimeException('SHOW CREATE table kind ambiguous');
                } elseif ($id === 'show.create.view' && ! isset($result['rows'][0]['Create View'])) {
                    throw new RuntimeException('SHOW CREATE view kind ambiguous');
                }
            }
        }
        witness($arm);
        if ($arm !== 'plain' && ! $unsupported) {
            must('publisher', 'control.ddl.'.$arm);
            must('publisher', 'warnings');
        }
        $end = must($arm, 'questions');
        note(['event' => 'arm_counter', 'role' => $arm, 'questions_before' => $start, 'questions_after' => $end, 'unsupported' => $unsupported, 'lock_safety' => $resourceAvailable ? 'SAMPLING_ONLY_NOT_PROOF' : 'INCONCLUSIVE']);
        finishArm($arm);
        $activeArm = null;
    }
    $outcome = 'COMPLETED_MECHANISM_ONLY';
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
    $observer = null;
    file_put_contents($dir.'/owned-clients.json', json_encode($owned, JSON_THROW_ON_ERROR));
    file_put_contents($dir.'/result.json', json_encode(['outcome' => $outcome, 'stop' => $stopped, 'retained_schemas' => $m['schemas'], 'owned' => $owned, 'resource_instrumentation_available' => $resourceAvailable, 'runtime_strategy_selected' => false, 'full_terminal_acceptance' => false, 'restricted_principal_visibility' => 'UNTESTED', 'no_sampled_wait_is_not_lock_freedom' => true], JSON_THROW_ON_ERROR));
    note(['event' => 'finished', 'outcome' => $outcome, 'stop' => $stopped, 'retained_schemas' => $m['schemas']]);
}
exit($stopped === null ? 0 : 1);
