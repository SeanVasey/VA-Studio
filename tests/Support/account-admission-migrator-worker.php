<?php

/** Fixed diagnostic worker. Never invoke during source preparation. No credentials in protocol/argv. */
use App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationStarted;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;

function accountMigratorMarker(array $value): void
{
    $line = json_encode($value, JSON_THROW_ON_ERROR)."\n";
    $stream = $GLOBALS['accountMigrator']['marker'];
    if (strlen($line) > 480 || \fwrite($stream, $line) !== strlen($line)) {
        exit(66); // Partial or full pipe is a failed barrier, never accepted.
    }
}

function accountMigratorWait(): void
{
    $read = [$GLOBALS['accountMigrator']['control']];
    $write = $except = [];
    if (\stream_select($read, $write, $except, 15) !== 1 || \fread($read[0], 1) !== 'G') {
        exit(65);
    }
}

/** No args/objects, application callbacks, SQL, logging exception chains or PDO access in handler. */
function accountMigratorSignal(int $signal): void
{
    $s = &$GLOBALS['accountMigrator'];
    $qualified = $signal === SIGUSR1 && $s['signals']++ === 0 && $s['started'] && $s['mode'] === 'overlap' && Fiber::getCurrent() === null;
    $trace = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 96);
    $qualified = $qualified && count($trace) < 96;
    $indices = [];
    $safe = [];
    foreach ($trace as $i => $f) {
        if (isset($f['args']) || isset($f['object'])) {
            $qualified = false;
        }
        $row = [];
        foreach (['class', 'function', 'type', 'file'] as $key) {
            $row[$key] = $f[$key] ?? '';
            if (! is_string($row[$key]) || strlen($row[$key]) > 512) {
                $qualified = false;
                $row[$key] = '';
            }
        }
        $row['line'] = $f['line'] ?? 0;
        $safe[] = $row;
        if ($row['class'] === AccountAdmissionUpgrade::class && $row['function'] === 'inspect') {
            $indices[] = $i;
        }
    }
    $qualified = $qualified && count($indices) === 1;
    $i = $indices[0] ?? -100;
    $expected = [
        [AccountAdmissionUpgrade::class, 'inspect', $s['upgrade'], 72],
        [AccountAdmissionUpgrade::class, 'change', $s['upgrade'], $s['direction'] === 'up' ? 15 : 20],
        [AccountAdmissionUpgrade::class, $s['direction'], $s['migration'], $s['direction'] === 'up' ? 13 : 18],
        [$s['migration_class'], $s['direction'], $s['migrator'], 518],
    ];
    foreach ($expected as $offset => [$class, $function, $file, $line]) {
        $f = $safe[$i + $offset] ?? [];
        $qualified = $qualified && ($f['class'] ?? null) === $class && ($f['function'] ?? null) === $function
            && ($f['type'] ?? null) === '->' && ($f['file'] ?? null) === $file && ($f['line'] ?? null) === $line;
    }
    $last = $i + 3;
    foreach ([[Migrator::class, 'runMethod'], [Migrator::class, 'runMigration'], [Migrator::class, $s['direction'] === 'up' ? 'runUp' : 'runDown'], [$s['command_class'], 'handle']] as [$class, $function]) {
        $matches = [];
        foreach ($safe as $j => $f) {
            if ($f['class'] === $class && $f['function'] === $function && $f['type'] === '->') {
                $matches[] = $j;
            }
        }
        $qualified = $qualified && count($matches) === 1 && ($matches[0] ?? -1) > $last;
        $last = $matches[0] ?? PHP_INT_MAX;
    }
    accountMigratorMarker(['kind' => 'signal', 'nonce' => $s['nonce'], 'pid' => \getmypid(), 'session' => $s['session'],
        'qualified' => $qualified, 'seq' => 1, 'frames' => count($trace), 'inspect_line' => $safe[$i]['line'] ?? 0, 'migration_line' => $safe[$i + 2]['line'] ?? 0, 'direction' => $s['direction'], 'trace_sha256' => hash('sha256', json_encode($safe, JSON_THROW_ON_ERROR))]);
    if (! \posix_kill(\getmypid(), SIGSTOP)) {
        exit(67);
    }
    exit(67); // Any external continuation cannot submit the future DROP.
}

try {
    [$script, $direction, $mode, $nonce] = $argv + [null, null, null, null];
    if ($argc !== 4 || ! in_array($direction, ['up', 'down'], true) || ! in_array($mode, ['overlap', 'end'], true)
        || ! is_string($nonce) || ! preg_match('/\A[a-f0-9]{32}\z/', $nonce) || PHP_VERSION !== '8.4.27' || PHP_SAPI !== 'cli' || ini_get('zend.exception_ignore_args') !== '1') {
        exit(64);
    }
    foreach (['pcntl_signal', 'pcntl_signal_get_handler', 'pcntl_async_signals', 'pcntl_waitpid', 'pcntl_wifstopped', 'pcntl_wstopsig',
        'posix_kill', 'getmypid', 'debug_backtrace', 'fwrite', 'stream_set_blocking', 'stream_select', 'hrtime'] as $function) {
        if (! function_exists($function) || ! (new ReflectionFunction($function))->isInternal()) {
            exit(64);
        }
    }
    if (phpversion('pcntl') !== PHP_VERSION || phpversion('posix') !== PHP_VERSION || \pcntl_signal_get_handler(SIGUSR1) !== SIG_DFL || \pcntl_signal_get_handler(SIGCHLD) !== SIG_DFL || Fiber::getCurrent() !== null) {
        exit(64);
    }
    $root = dirname(__DIR__, 2);
    $pins = json_decode(file_get_contents($root.'/docs/verification/account-migrator-evidence-20261102/pins.json'), true, 16, JSON_THROW_ON_ERROR);
    if (! hash_equals($pins['php_binary_sha256'], hash_file('sha256', PHP_BINARY))) {
        exit(64);
    }
    foreach ($pins['sha256'] as $path => $hash) {
        if (! is_file($root.'/'.$path) || ! hash_equals($hash, hash_file('sha256', $root.'/'.$path))) {
            exit(64);
        }
    }
    $callLines = file($root.'/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php');
    if ($pins['migrator_run_method_line'] !== 518 || trim($callLines[517] ?? '') !== '$migration->{$method}();') {
        exit(64); // Exact pinned call expression, never an arbitrary line range.
    }
    $marker = fopen('php://fd/3', 'wb');
    $control = fopen('php://fd/4', 'rb');
    if ($marker === false || $control === false || ! \stream_set_blocking($marker, false)) {
        exit(64);
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! $app->environment('testing') || config('app.debug') !== false || DB::getDriverName() !== 'mysql' || DB::getDatabaseName() !== 'vaseyaudio_production_identity'
 || get_class($app->make('migrator')) !== Migrator::class
        || get_class($app->make('migration.repository')) !== Illuminate\Database\Migrations\DatabaseMigrationRepository::class) {
        exit(64);
    }
    $pdo = DB::connection()->getPdo();
    if ($pdo->inTransaction() || DB::transactionLevel() !== 0) {
        exit(64);
    }
    $GLOBALS['accountMigrator'] = ['marker' => $marker, 'control' => $control, 'nonce' => $nonce, 'mode' => $mode,
        'direction' => $direction, 'session' => (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn(), 'server' => hash('sha256', $pdo->query('SELECT @@server_uuid')->fetchColumn()), 'started' => false,
        'signals' => 0, 'migration_class' => '', 'upgrade' => $root.'/app/Domain/Customers/ProductionIdentity/AccountAdmissionUpgrade.php',
        'migration' => $root.'/database/migrations/2026_11_01_269000_customer_account_admission_v2.php',
        'migrator' => realpath($root.'/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php'),
        'command_class' => $direction === 'up' ? Illuminate\Database\Console\Migrations\MigrateCommand::class : Illuminate\Database\Console\Migrations\RollbackCommand::class];
    if ((new ReflectionClass(AccountAdmissionUpgrade::class))->getFileName() !== $GLOBALS['accountMigrator']['upgrade']
        || (new ReflectionClass(Migrator::class))->getFileName() !== $GLOBALS['accountMigrator']['migrator']) {
        exit(64);
    }
    $eventCheck = static function ($event) use ($direction): bool {
        return $event->name === '2026_11_01_269000_customer_account_admission_v2' && $event->method === $direction
            && (new ReflectionClass($event->migration))->getFileName() === $GLOBALS['accountMigrator']['migration'];
    };
    $app['events']->listen(MigrationStarted::class, static function ($event) use ($eventCheck, $pdo): void {
        if (! $eventCheck($event)) {
            exit(68);
        }
        $s = &$GLOBALS['accountMigrator'];
        if ($s['started'] || (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn() !== $s['session']) {
            exit(68);
        }
        $s['started'] = true;
        $s['migration_class'] = get_class($event->migration);
        accountMigratorMarker(['kind' => 'started', 'nonce' => $s['nonce'], 'pid' => \getmypid(), 'session' => $s['session']]);
    });
    if ($mode === 'overlap') {
        if (\pcntl_async_signals() !== false || ! \pcntl_signal(SIGUSR1, 'accountMigratorSignal')) {
            exit(64);
        }
        \pcntl_async_signals(true);
        if (! \pcntl_async_signals() || \pcntl_signal_get_handler(SIGUSR1) !== 'accountMigratorSignal') {
            exit(64);
        }
    }
    if ($mode === 'end') {
        $app['events']->listen(MigrationEnded::class, static function ($event) use ($eventCheck): void {
            $s = $GLOBALS['accountMigrator'];
            if (! $eventCheck($event) || ! $s['started']) {
                exit(68);
            }
            accountMigratorMarker(['kind' => 'end', 'nonce' => $s['nonce'], 'pid' => \getmypid(), 'session' => $s['session']]);
            accountMigratorWait();
            exit(68); // A released END barrier is not a normal completion.
        });
    }
    $s = $GLOBALS['accountMigrator'];
    accountMigratorMarker(['kind' => 'ready', 'nonce' => $nonce, 'pid' => \getmypid(), 'session' => $s['session'], 'server' => $s['server']]);
    accountMigratorWait();
    $arguments = ['--path' => ['database/migrations/2026_11_01_269000_customer_account_admission_v2.php'], '--force' => true, '--no-interaction' => true];
    if ($direction === 'down') {
        $arguments['--step'] = 1;
    }
    $exit = $kernel->call($direction === 'up' ? 'migrate' : 'migrate:rollback', $arguments);
    if (! $GLOBALS['accountMigrator']['started']) {
        exit(68);
    }
    accountMigratorMarker(['kind' => 'done', 'nonce' => $nonce, 'pid' => \getmypid(), 'session' => $s['session'], 'exit' => $exit]);
    exit($exit);
} catch (Throwable) {
    // No exception text/chain/args can expose a descriptor, credential or synthetic row.
    exit(70);
}
