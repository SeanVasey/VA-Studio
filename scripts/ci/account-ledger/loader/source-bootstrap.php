<?php
declare(strict_types=1);

// Source binding only. No migration listener, signal/reaper/IPC, SQL, credential or protocol operation.
try {
    require_once __DIR__.'/bootstrap.php';
    $root = '/candidate';
    $case = getenv('VA_ACCOUNT_LEDGER_CASE');
    $sha = getenv('VA_ACCOUNT_LEDGER_SOURCE_SHA');
    $directory = getenv('VA_ACCOUNT_LEDGER_EVIDENCE');
    $outer = getenv('VA_ACCOUNT_LEDGER_RESULT_DIR');
    \AccountIntegratedHarness\Bootstrap::require(in_array($case, ['up-overlap', 'down-overlap', 'up-end', 'down-end'], true)
        && is_string($sha) && preg_match('/\A[0-9a-f]{40}\z/D', $sha) === 1
        && $outer === '/evidence'
        && is_string($directory) && dirname($directory) === $outer.'/launcher'
        && preg_match('/\Aaccount-migrator-one-[a-z0-9_]{8}\z/D', basename($directory)) === 1
        && realpath($directory) === $directory && ! is_link($directory) && (fileperms($directory) & 0777) === 0700
        && PHP_VERSION === '8.4.27' && ini_get('memory_limit') === '512M'
        && ini_get('auto_append_file') === '' && ini_get('disable_functions') === '');
    $parent = (string) getmypid() === getenv('VA_ACCOUNT_LEDGER_PARENT_PID');
    $role = $parent ? 'parent' : '';
    $direction = null;
    if (! $parent) {
        \AccountIntegratedHarness\Bootstrap::require(ini_get('auto_prepend_file') === __FILE__);
        if (($argv[0] ?? null) === $root.'/tests/Support/account-admission-migrator-worker.php') {
            [$expectedDirection, $expectedCut] = explode('-', $case);
            \AccountIntegratedHarness\Bootstrap::require(count($argv) === 4 && $argv[1] === $expectedDirection && $argv[2] === $expectedCut
                && preg_match('/\A[0-9a-f]{32}\z/D', $argv[3]) === 1);
            $role = 'worker'; $direction = $expectedDirection;
        } elseif (($argv[0] ?? null) === $root.'/artisan') {
            $tail = array_slice($argv, 1);
            $path = '--path=database/migrations/2026_11_01_269000_customer_account_admission_v2.php';
            if ($tail === ['migrate', $path, '--force', '--no-interaction']) { $direction = 'up'; }
            if ($tail === ['migrate:rollback', $path, '--force', '--no-interaction', '--step=1']) { $direction = 'down'; }
            \AccountIntegratedHarness\Bootstrap::require($direction !== null);
            $role = 'ordinary_artisan';
        } else {
            throw new RuntimeException('Ledger source entry refused.');
        }
    } else {
        \AccountIntegratedHarness\Bootstrap::require(ini_get('auto_prepend_file') === '');
    }
    $pins = json_decode(file_get_contents($root.'/docs/verification/account-migrator-evidence-20261102/pins.json'), true, 64, JSON_THROW_ON_ERROR);
    $feature = 'tests/Supervised/ProductionIdentity/AccountAdmissionMigratorTest.php';
    $selection = $parent ? ['class' => 'Tests\Supervised\ProductionIdentity\AccountAdmissionMigratorTest',
        'test_path' => $feature, 'test_sha256' => $pins['sha256'][$feature],
        'case_name' => 'test_normal_migrator_process_interruption_and_genuine_ledger_resume with data set "'.$case.'"'] : null;
    require_once __DIR__.'/Job.php';
    $record = \AccountLedgerPortable\Job::load();
    \AccountIntegratedHarness\Bootstrap::require($record['candidate_sha'] === $sha && $record['case'] === $case);
    $binding = $record['binding'];
    \AccountIntegratedHarness\Bootstrap::prepare($binding, $sha, $selection, $directory);
    $GLOBALS['_composer_autoload_path'] = $binding['vendor_realpath'].'/autoload.php';
    \AccountIntegratedHarness\Bootstrap::require(realpath($GLOBALS['_composer_autoload_path']) === $GLOBALS['_composer_autoload_path']
        && (new ReflectionClass(\App\Domain\Customers\ProductionIdentity\AccountAdmissionUpgrade::class))->getFileName()
            === $root.'/app/Domain/Customers/ProductionIdentity/AccountAdmissionUpgrade.php');
    $facts = ['candidate_sha' => $sha, 'case' => $case, 'pid' => getmypid(), 'role' => $role, 'direction' => $direction,
        'source_origins' => true, 'representatives' => true, 'vendor_binding' => true, 'application_constructed_claim' => false];
    $boundPath = $directory.'/origin-'.getmypid().'-bound.json';
    $bytes = json_encode($facts, JSON_THROW_ON_ERROR)."\n";
    \AccountIntegratedHarness\Bootstrap::require(! file_exists($boundPath) && ! is_link($boundPath)
        && file_put_contents($boundPath, $bytes, LOCK_EX) === strlen($bytes) && chmod($boundPath, 0600)
        && file_get_contents($boundPath) === $bytes);
    if ($role === 'ordinary_artisan') {
        // Read-only final source observation; no migration/event/coordinator instrumentation.
        register_shutdown_function(static function () use ($directory, $facts, $root): void {
            $final = $facts;
            $final['application_origin'] = false;
            try {
                \AccountIntegratedHarness\Bootstrap::origins();
                \AccountIntegratedHarness\Bootstrap::representatives();
                $app = (new ReflectionProperty(\Illuminate\Container\Container::class, 'instance'))->getValue();
                \AccountIntegratedHarness\Bootstrap::require($app instanceof \Illuminate\Foundation\Application
                    && $app->basePath() === $root && $app->path() === $root.'/app' && $app->configPath() === $root.'/config'
                    && $app->databasePath() === $root.'/database' && $app->bootstrapPath() === $root.'/bootstrap'
                    && $app->storagePath() === $directory.'/runtime/storage'
                    && in_array($root.'/bootstrap/app.php', get_included_files(), true));
                $final['application_origin'] = true;
                $path = $directory.'/origin-'.getmypid().'-final.json';
                $bytes = json_encode($final, JSON_THROW_ON_ERROR)."\n";
                \AccountIntegratedHarness\Bootstrap::require(! file_exists($path) && ! is_link($path)
                    && file_put_contents($path, $bytes, LOCK_EX) === strlen($bytes) && chmod($path, 0600)
                    && file_get_contents($path) === $bytes);
            } catch (Throwable) { exit(125); }
        });
    }
} catch (Throwable) { exit(125); }
