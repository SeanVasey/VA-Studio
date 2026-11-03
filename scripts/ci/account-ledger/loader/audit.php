<?php
declare(strict_types=1);

// Entire owned server, with the accepted strict foreground classifier. No App,
// migrations, data mutation, instrumentation changes or console diagnostics.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
require __DIR__.'/Job.php';
require '/candidate/tests/Support/AccountMigratorForeground.php';
$pdo = null;
$statement = null;
$clean = false;
try {
    $job = \AccountLedgerPortable\Job::load();
    $password = \AccountLedgerPortable\Job::password($job);
    $pdo = new Pdo\Mysql('mysql:host='.$job['service']['ip'].';port=3306;dbname=vaseyaudio_production_identity;charset=utf8mb4',
        'account_fixture', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_TIMEOUT => 5]);
    unset($password);
    $weak = WeakReference::create($pdo);
    $need = static function (bool $ok): void {
        if (!$ok) { throw new LogicException('Owned server audit refused.'); }
    };
    $row = static function (string $sql) use (&$pdo, &$statement, $need): array {
        $statement = $pdo->prepare($sql);
        $need($statement instanceof PDOStatement && $pdo->getWarningCount() === 0);
        $need($statement->execute() && $pdo->getWarningCount() === 0);
        $result = $statement->fetch(PDO::FETCH_NUM);
        $need(is_array($result) && $pdo->getWarningCount() === 0);
        $need($statement->fetch(PDO::FETCH_NUM) === false && $pdo->getWarningCount() === 0);
        $need($statement->closeCursor() && $pdo->getWarningCount() === 0);
        $statement = null;
        return $result;
    };
    $need($row('SELECT CURRENT_USER(),@@server_uuid,DATABASE(),@@performance_schema')
        === ['account_fixture@%', $job['service']['server_uuid'], 'vaseyaudio_production_identity', 1]);
    [$session, $thread] = $row('SELECT CONNECTION_ID(),THREAD_ID FROM performance_schema.threads WHERE PROCESSLIST_ID=CONNECTION_ID()');
    $need(is_int($session) && $session > 0 && is_int($thread) && $thread > 0);
    $statement = $pdo->prepare("SELECT t.THREAD_ID,t.NAME,t.TYPE,t.PROCESSLIST_ID,t.PROCESSLIST_COMMAND,t.PROCESSLIST_DB,(t.PROCESSLIST_USER IS NULL) AS USER_IS_NULL,t.INSTRUMENTED,e.EVENT_ID,e.END_EVENT_ID FROM performance_schema.threads t LEFT JOIN performance_schema.events_statements_current e ON e.THREAD_ID=t.THREAD_ID WHERE t.TYPE='FOREGROUND' AND t.PROCESSLIST_ID IS NOT NULL ORDER BY t.PROCESSLIST_ID");
    $need($statement->execute() && $pdo->getWarningCount() === 0);
    $rows = [];
    while (($item = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $need($pdo->getWarningCount() === 0 && count($rows) < 256);
        $rows[] = $item;
    }
    $need($statement->closeCursor() && $pdo->getWarningCount() === 0);
    $statement = null;
    $classified = \Tests\Support\AccountMigratorForeground::classify($rows);
    $need(count($classified['clients']) === 1 && (int) $classified['clients'][0]['PROCESSLIST_ID'] === $session
        && $classified['clients'][0]['INSTRUMENTED'] === 'YES');
    $need($row("SELECT COUNT(*) FROM performance_schema.events_statements_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=CONNECTION_ID() AND t.INSTRUMENTED='YES' AND e.END_EVENT_ID IS NULL AND e.EVENT_NAME LIKE 'statement/%'") === [1]);
    $need($row('SELECT COUNT(*) FROM performance_schema.data_lock_waits') === [0]);
    // Every NULL/orphan owner remains included. Only the positive auditor is excluded.
    foreach ([['information_schema.innodb_trx', 'trx_mysql_thread_id', $session],
        ['performance_schema.data_locks', 'THREAD_ID', $thread],
        ['performance_schema.metadata_locks', 'OWNER_THREAD_ID', $thread]] as [$table, $column, $id]) {
        $need($row('SELECT COUNT(*) FROM '.$table.' WHERE '.$column.'<>'.(string) $id.' OR '.$column.' IS NULL') === [0]);
    }
    $need($row("SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE LOCK_STATUS='PENDING'") === [0]);
    $lock = 'account-guard-v2:'.substr(hash('sha256', 'vaseyaudio_production_identity'), 0, 40);
    $need($row("SELECT IS_USED_LOCK('".$lock."')") === [null]);
    $need($row("SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_statements_current','events_statements_history_long') AND ENABLED='YES'") === [4]);
    $need($row('SELECT CURRENT_USER(),@@server_uuid,DATABASE()')
        === ['account_fixture@%', $job['service']['server_uuid'], 'vaseyaudio_production_identity']);
    $clean = !$pdo->inTransaction();
} catch (Throwable) {
    $clean = false;
} finally {
    if ($statement instanceof PDOStatement) { $clean = $statement->closeCursor() && $clean; }
    $statement = null;
    $pdo = null;
    $clean = $clean && isset($weak) && $weak->get() === null;
    unset($password);
}
if (!$clean) { exit(2); }
echo "{\"whole_server_resources_zero\":true,\"strict_foreground\":true,\"full_eof\":true,\"pdo_closed\":true}\n";
