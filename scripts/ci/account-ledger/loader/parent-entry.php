<?php
declare(strict_types=1);
ini_set('display_errors', '0'); ini_set('log_errors', '0'); umask(0077);
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

try {
    requireProduct($argc === 17);
    [$entry, $case, $directory, $nonce] = array_slice($argv, 0, 4);
    requireProduct(in_array($case, ['up-overlap', 'down-overlap', 'up-end', 'down-end'], true)
        && preg_match('/\A[0-9a-f]{32}\z/D', $nonce) === 1);
    $expected = ['--colors=never', '--filter', 'test_normal_migrator_process_interruption_and_genuine_ledger_resume.*"'.$case.'"',
        '--stop-on-failure', '--stop-on-error', '--stop-on-warning', '--fail-on-risky', '--fail-on-warning',
        '--fail-on-incomplete', '--fail-on-skipped', '--log-junit', $directory.'/junit.xml',
        'tests/Supervised/ProductionIdentity/AccountAdmissionMigratorTest.php'];
    requireProduct(array_slice($argv, 4) === $expected);
    require_once __DIR__.'/Job.php';
    $record = \AccountLedgerPortable\Job::load();
    $root = '/candidate';
    $sha = $record['candidate_sha'];
    $ip = $record['service']['ip'];
    $outer = '/evidence';
    requireProduct($record['case'] === $case && getenv('VA_ACCOUNT_LEDGER_SOURCE_SHA') === $sha
        && getenv('VA_ACCOUNT_LEDGER_RESULT_DIR') === $outer
        && dirname($directory) === $outer.'/launcher'
        && preg_match('/\Aaccount-migrator-one-[a-z0-9_]{8}\z/D', basename($directory)) === 1
        && realpath($directory) === $directory && !is_link($directory)
        && (fileperms($directory) & 0777) === 0700 && fileowner($directory) === 1000
        && PHP_VERSION === '8.4.27' && ini_get('memory_limit') === '512M'
        && ini_get('auto_prepend_file') === '' && ini_get('auto_append_file') === ''
        && ini_get('disable_functions') === '');
    $password = \AccountLedgerPortable\Job::password($record);
    $GLOBALS['accountMigratorQualifiedRuntime'] = $record['runtime'];
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
    require __DIR__.'/bootstrap.php';
    foreach (['VA_ACCOUNT_LEDGER_CASE' => $case, 'VA_ACCOUNT_LEDGER_EVIDENCE' => $directory,
        'VA_ACCOUNT_LEDGER_PARENT_PID' => (string) getmypid()] as $name => $value) {
        \AccountIntegratedHarness\Bootstrap::environment($name, $value);
    }
    \AccountIntegratedHarness\Bootstrap::privateDirectories($directory);
    require __DIR__.'/source-bootstrap.php';
    require __DIR__.'/OriginWitness.php';
    $ini = $directory.'/runtime/ini';
    requireProduct(mkdir($ini, 0700));
    $bytes = 'auto_prepend_file="'.__DIR__.'/source-bootstrap.php"'."\nmemory_limit=512M\n";
    requireProduct(file_put_contents($ini.'/99-ledger-source.ini', $bytes) === strlen($bytes) && chmod($ini.'/99-ledger-source.ini', 0600));
    $scan = getenv('PHP_INI_SCAN_DIR');
    \AccountIntegratedHarness\Bootstrap::environment('PHP_INI_SCAN_DIR', ($scan === false ? '' : $scan).':'.$ini);
    $GLOBALS['accountMigratorSelectedCase'] = $case;
    $GLOBALS['accountMigratorEvidenceDirectory'] = $directory;
    $GLOBALS['accountMigratorSupervisorNonce'] = $nonce;
    $vendor = \AccountIntegratedHarness\Bootstrap::$context['vendor'];
    $argv = array_merge([$vendor.'/phpunit/phpunit/phpunit'], $expected,
        ['--extension', \AccountIntegratedHarness\OriginWitness::class]);
    $argc = count($argv); $_SERVER['argv'] = $argv; $_SERVER['argc'] = $argc;
    $GLOBALS['_composer_autoload_path'] = $vendor.'/autoload.php';
    requireProduct(realpath($GLOBALS['_composer_autoload_path']) === $GLOBALS['_composer_autoload_path'] && chdir($root));
} catch (Throwable) {
    fwrite(STDERR, "ACCOUNT_LEDGER_PARENT_LOADER_REFUSED\n"); exit(125);
}
require $vendor.'/phpunit/phpunit/phpunit';
