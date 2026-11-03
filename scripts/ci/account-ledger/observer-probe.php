<?php
declare(strict_types=1);

// Same-app permission transition only; no application tests, grants, DDL or DML.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
umask(0077);

function requireProduct(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('Product test loader refused.');
    }
}

function sameProductFile(array $before, array $after): bool
{
    foreach (['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'] as $key) {
        if ($before[$key] !== $after[$key]) {
            return false;
        }
    }

    return true;
}

/** Native rows stay in RAM; every successful query drains exactly one row. */
function qualifiedRow(Pdo\Mysql $pdo, string $sql): array
{
    $statement = null;
    try {
        $statement = $pdo->prepare($sql);
        requireProduct($statement instanceof PDOStatement && $pdo->getWarningCount() === 0);
        requireProduct($statement->execute() && $pdo->getWarningCount() === 0);
        $row = $statement->fetch(PDO::FETCH_NUM);
        requireProduct(is_array($row) && $pdo->getWarningCount() === 0);
        requireProduct($statement->fetch(PDO::FETCH_NUM) === false && $pdo->getWarningCount() === 0);
        requireProduct($statement->closeCursor() && $pdo->getWarningCount() === 0);
        $statement = null;

        return $row;
    } finally {
        if ($statement instanceof PDOStatement) {
            requireProduct($statement->closeCursor());
        }
    }
}

/** Only this exact native permission error is the expected baseline. */
function qualifiedDenial(Pdo\Mysql $pdo, string $sql, int $nativeCode): void
{
    $statement = null;
    $denied = false;
    try {
        $statement = $pdo->prepare($sql);
        requireProduct($statement instanceof PDOStatement);
        $statement->execute();
    } catch (PDOException $error) {
        requireProduct(is_array($error->errorInfo) && count($error->errorInfo) === 3 && $error->errorInfo[0] === '42000'
            && $error->errorInfo[1] === $nativeCode && $error->getCode() === '42000');
        $denied = true;
    } finally {
        if ($statement instanceof PDOStatement) {
            requireProduct($statement->closeCursor());
        }
        $statement = null;
    }
    requireProduct($denied); // Success, disconnect or any other exception refuses before grants.
    // The expected denial has diagnostics; a new successful statement must clear them.
    requireProduct(qualifiedRow($pdo, 'SELECT 1') === [1]);
}

$pdo = null;
$statement = null;
$refused = false;
$facts = array_fill_keys(['identity_match', 'existing_observer_access', 'own_instrumented_thread',
    'selected_permission_boundary', 'full_eof', 'cursors_closed', 'pdo_closed'], false);
try {
    requireProduct($argc === 5);
    [$entry, $directory, $ip, $admissionPin, $phase] = $argv;
    requireProduct(in_array($phase, ['before', 'after'], true));
    if ($phase === 'after') {
        $facts += array_fill_keys(['own_current_event', 'instrumentation_match', 'global_resource_access'], false);
    }
    requireProduct($directory === '/evidence' && !is_link($directory) && realpath($directory) === $directory);
    $directoryStat = lstat($directory);
    requireProduct(is_array($directoryStat) && ($directoryStat['mode'] & 0170000) === 0040000
        && ($directoryStat['mode'] & 0777) === 0700 && $directoryStat['uid'] === 1000);
    $root = '/'; // Qualification constructs no application or dependency autoloader.
    require __DIR__.'/observer-admission.php';
    foreach (['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'LOG_CHANNEL' => 'null',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'CI' => 'true', 'GITHUB_ACTIONS' => 'true', 'PAO_DISABLE' => '1',
        'DB_URL' => '', 'DB_SOCKET' => '', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $ip, 'DB_PORT' => '3306',
        'DB_DATABASE' => 'vaseyaudio_production_identity', 'DB_USERNAME' => 'account_fixture', 'DB_PASSWORD' => $password,
        'VA_LOCAL_DISPOSABLE_MYSQL' => 'vaseyaudio_production_identity',
        'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false'] as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    unset($password, $value);
    putenv('VA_IDENTITY_ISOLATED_NATIVE');
    unset($_ENV['VA_IDENTITY_ISOLATED_NATIVE'], $_SERVER['VA_IDENTITY_ISOLATED_NATIVE']);
    requireProduct(chdir($root) && PHP_VERSION === '8.4.27');
    $warningGetter = new ReflectionMethod(Pdo\Mysql::class, 'getWarningCount');
    requireProduct($warningGetter->isInternal() && $warningGetter->getDeclaringClass()->getName() === Pdo\Mysql::class
        && $warningGetter->getNumberOfParameters() === 0 && $warningGetter->getReturnType()?->getName() === 'int');
    $pdo = new Pdo\Mysql('mysql:host='.$ip.';port=3306;dbname=vaseyaudio_production_identity;charset=utf8mb4',
        $_ENV['DB_USERNAME'], $_ENV['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_CASE => PDO::CASE_NATURAL, PDO::ATTR_ORACLE_NULLS => PDO::NULL_NATURAL, PDO::ATTR_TIMEOUT => 5]);
    requireProduct($pdo::class === Pdo\Mysql::class && (new ReflectionClass($pdo))->isInternal()
        && $pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES) === false && $pdo->getWarningCount() === 0);
    $weak = WeakReference::create($pdo);
    requireProduct(qualifiedRow($pdo, 'SELECT CURRENT_USER(), @@GLOBAL.server_uuid, DATABASE()')
        === ['account_fixture@%', $admission['server_uuid'], 'vaseyaudio_production_identity']);
    $facts['identity_match'] = true;
    requireProduct(qualifiedRow($pdo, "SELECT COUNT(*) FROM performance_schema.threads WHERE PROCESSLIST_ID=CONNECTION_ID() AND TYPE='FOREGROUND' AND PROCESSLIST_DB='vaseyaudio_production_identity' AND INSTRUMENTED='YES' AND HISTORY='YES'") === [1]);
    $facts['own_instrumented_thread'] = true;
    requireProduct(qualifiedRow($pdo, 'SELECT COUNT(*) FROM performance_schema.data_locks') === [0]);
    requireProduct(qualifiedRow($pdo, 'SELECT COUNT(*) FROM performance_schema.data_lock_waits') === [0]);
    $facts['existing_observer_access'] = true;

    if ($phase === 'before') {
        foreach (['metadata_locks', 'events_statements_current', 'setup_consumers', 'setup_instruments'] as $table) {
            qualifiedDenial($pdo, 'SELECT COUNT(*) FROM performance_schema.'.$table, 1142);
        }
        qualifiedDenial($pdo, 'SELECT COUNT(*) FROM information_schema.INNODB_TRX', 1227);
        $facts['selected_permission_boundary'] = true;
    } else {
        // Positive own-thread witness, not acceptance based on an empty filtered view.
        requireProduct(qualifiedRow($pdo, "SELECT COUNT(*) FROM performance_schema.events_statements_current e JOIN performance_schema.threads t ON t.THREAD_ID=e.THREAD_ID WHERE t.PROCESSLIST_ID=CONNECTION_ID() AND t.INSTRUMENTED='YES' AND e.END_EVENT_ID IS NULL AND e.EVENT_NAME LIKE 'statement/%'") === [1]);
        $facts['own_current_event'] = true;
        requireProduct(qualifiedRow($pdo, "SELECT COUNT(*) FROM performance_schema.setup_consumers WHERE NAME IN ('global_instrumentation','thread_instrumentation','events_statements_current','events_statements_history_long') AND ENABLED='YES'") === [4]);
        $counts = qualifiedRow($pdo, "SELECT (SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%'),(SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%' AND ENABLED='YES')");
        requireProduct(count($counts) === 2 && is_int($counts[0]) && $counts[0] >= 3 && $counts[0] <= 10000
            && $counts[1] === $counts[0]);
        requireProduct(qualifiedRow($pdo, "SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME IN ('statement/sql/select','statement/sql/create_trigger','statement/sql/drop_trigger') AND ENABLED='YES'") === [3]);
        requireProduct(qualifiedRow($pdo, "SELECT COUNT(*) FROM performance_schema.setup_instruments WHERE NAME='wait/lock/metadata/sql/mdl' AND ENABLED='YES'") === [1]);
        unset($counts);
        $facts['instrumentation_match'] = true;

        // INNODB_TRX is PROCESS-gated, rather than a silent same-user row filter.
        // Parent requires its exact pre-denial and complete USAGE->PROCESS delta.
        requireProduct(qualifiedRow($pdo, 'SELECT COUNT(*) FROM information_schema.INNODB_TRX') === [0]);
        // Include NULL/orphan owners, excluding only this positively identified auditor.
        requireProduct(qualifiedRow($pdo, 'SELECT COUNT(*) FROM performance_schema.metadata_locks m LEFT JOIN performance_schema.threads t ON t.THREAD_ID=m.OWNER_THREAD_ID WHERE t.PROCESSLIST_ID IS NULL OR t.PROCESSLIST_ID<>CONNECTION_ID()') === [0]);
        requireProduct(qualifiedRow($pdo, "SELECT COUNT(*) FROM performance_schema.metadata_locks WHERE LOCK_STATUS='PENDING'") === [0]);
        $facts['global_resource_access'] = true;
        $facts['selected_permission_boundary'] = true;
    }
    // Identity and native diagnostics must still be intact after all permission checks.
    requireProduct(qualifiedRow($pdo, 'SELECT CURRENT_USER(), @@GLOBAL.server_uuid, DATABASE()')
        === ['account_fixture@%', $admission['server_uuid'], 'vaseyaudio_production_identity']);
    $facts['full_eof'] = true;
    $facts['cursors_closed'] = true;
} catch (Throwable) {
    // No native message, grant body, credential or result row leaves this process.
    $refused = true;
} finally {
    $statement = null;
    $pdo = null;
    $facts['pdo_closed'] = isset($weak) && $weak->get() === null;
    putenv('DB_PASSWORD');
    unset($_ENV['DB_PASSWORD'], $_SERVER['DB_PASSWORD'], $password, $counts, $admission);
}
if ($refused || in_array(false, $facts, true)) {
    $facts['selected_permission_boundary'] = false;
}
$line = json_encode($facts, JSON_THROW_ON_ERROR)."\n";
$written = fwrite(STDOUT, $line);
exit(! $refused && ! in_array(false, $facts, true) && $written === strlen($line) ? 0 : 2);
