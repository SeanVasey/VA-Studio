<?php

declare(strict_types=1);
use Dotenv\Parser\Parser;

// File inspection only: no Laravel boot, shell evaluation, service calls or writes.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (int $severity): never {
    throw new RuntimeException('Inspection failed.');
});

$checks = [];
$mode = 'configuration';
$runtime = false;
// production (the default) demands every test path off; staging admits only the Stripe-test commerce chain.
$profile = 'production';
$root = dirname(__DIR__, 2);
$record = static function (string $id, bool $ok) use (&$checks): void {
    $checks[] = ['id' => $id, 'status' => $ok ? 'pass' : 'blocked'];
};
$finish = static function () use (&$checks, &$mode, &$runtime, &$profile): never {
    $blocked = in_array('blocked', array_column($checks, 'status'), true);
    echo json_encode([
        'schema_version' => 1,
        'scope' => $mode === 'template' ? 'template_file' : 'configuration_file',
        'profile' => $profile,
        'result' => $blocked ? 'BLOCKED' : ($mode === 'template' ? 'TEMPLATE_VALID' : 'FILE_CHECKS_PASSED'),
        'deployment_ready' => false,
        'runtime_inspection_requested' => $runtime,
        'checks' => $checks,
        'unverified' => [
            'effective_cached_and_process_configuration', 'mysql_version_connection_grants_and_schema',
            'web_tls_proxy_and_private_file_exposure', 'worker_supervision_and_shared_storage',
            'scanner_signatures_and_detection', 'media_tag_approval_hash_and_processing',
            'mail_and_scheduler_delivery', 'backup_restore_and_rollback', 'production_commerce_and_cutover',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit($blocked ? 1 : 0);
};

// Require canonical paths and reject symlinks at every existing component.
$canonical = static function (string $path): bool {
    if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
        return false;
    }
    $cursor = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            return false;
        }
        $cursor .= '/'.$part;
        if (is_link($cursor)) {
            return false;
        }
    }

    return realpath($path) === $path;
};

try {
    $arguments = array_slice($argv, 1);
    if ($arguments === ['--template']) {
        $mode = 'template';
        $path = $root.'/ops/private-server/env.example';
    } elseif (count($arguments) >= 2 && $arguments[0] === '--env-file'
        && ($options = array_slice($arguments, 2)) === array_unique($options)
        && array_diff($options, ['--runtime', '--profile=production', '--profile=staging']) === []
        && count(preg_grep('/\A--profile=/', $options)) <= 1) {
        $path = $arguments[1];
        $runtime = in_array('--runtime', $options, true);
        $profile = in_array('--profile=staging', $options, true) ? 'staging' : 'production';
    } else {
        $record('usage_template_or_absolute_env_file_with_optional_runtime', false);
        $finish();
    }

    $record('dotenv_dependency_available', is_file($root.'/vendor/autoload.php'));
    if (end($checks)['status'] === 'blocked') {
        $finish();
    }
    require $root.'/vendor/autoload.php';

    $record('input_canonical_regular_file', $canonical($path) && is_file($path));
    if (end($checks)['status'] === 'blocked') {
        $finish();
    }
    $before = lstat($path);
    $record('input_bounded_single_link', $before['size'] <= 131072 && $before['nlink'] === 1);
    if ($mode !== 'template') {
        // Owner read/write and trusted group read are permitted; no world access,
        // group write or executable/special bits. Do not read an exposed secret file.
        $record('input_permissions', ($before['mode'] & 07137) === 0);
    }
    if (in_array('blocked', array_column($checks, 'status'), true)) {
        $finish();
    }
    $handle = fopen($path, 'rb');
    try {
        $opened = fstat($handle);
        $source = stream_get_contents($handle, 131073);
        clearstatcache(true, $path);
        $after = lstat($path);
        $stable = $canonical($path) && strlen($source) <= 131072;
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'mtime', 'ctime'] as $field) {
            $stable = $stable && $before[$field] === $opened[$field] && $opened[$field] === $after[$field];
        }
        $record('input_stable_read', $stable);
    } finally {
        fclose($handle);
    }
    if (! $stable) {
        $finish();
    }

    try {
        // This preparation format uses one explicit scalar assignment per line.
        // phpdotenv can silently omit an unfinished quoted final line; requiring
        // one parsed entry for each non-comment line catches that truncation.
        foreach (preg_split('/\r\n|\n|\r/', $source) as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            $single = (new Parser)->parse($line."\n");
            if (count($single) !== 1 || ! $single[0]->getValue()->isDefined()) {
                throw new RuntimeException('Explicit single-line assignments required.');
            }
        }
        $entries = (new Parser)->parse($source);
        $names = array_map(static fn ($entry) => $entry->getName(), $entries);
        $record('dotenv_unique_keys', count($names) === count(array_unique($names)));
        // Resolves references only against this file, never the caller's environment.
        $env = Dotenv\Dotenv::parse($source);
        $record('dotenv_syntax', true);
    } catch (Throwable) {
        // Parser exceptions can quote the offending secret. Never expose them.
        $record('dotenv_syntax', false);
        $finish();
    }
    $value = static fn (string $key): string => (string) ($env[$key] ?? '');
    $is = static fn (string $key, string $expected): bool => array_key_exists($key, $env) && $value($key) === $expected;

    // Test-commerce switches: forced off in production; in staging each must still be an explicit boolean.
    $testSwitches = ['STRIPE_WEBHOOK_ENABLED', 'STRIPE_TEST_CHECKOUT_ENABLED', 'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED',
        'STRIPE_TEST_FINALIZATION_ENABLED', 'VASEY_TEST_CONTRACT_ISSUANCE_ENABLED',
        'VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED', 'VASEY_TEST_DELIVERY_ACCESS_ENABLED'];
    $baseline = [
        'APP_ENV' => $profile, 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql',
        'SESSION_DRIVER' => 'database', 'SESSION_ENCRYPT' => 'true', 'SESSION_DOMAIN' => 'null',
        'SESSION_SECURE_COOKIE' => 'true', 'SESSION_HTTP_ONLY' => 'true', 'SESSION_SAME_SITE' => 'lax',
        'CACHE_STORE' => 'database', 'QUEUE_CONNECTION' => 'database', 'FILESYSTEM_DISK' => 'local',
        'LOG_CHANNEL' => 'stderr', 'LOG_LEVEL' => 'info', 'MAIL_MAILER' => 'log', 'STRIPE_MODE' => 'test',
        'CONTACT_INQUIRIES_ENABLED' => 'false',
    ];
    if ($profile === 'production') {
        $baseline += array_fill_keys($testSwitches, 'false');
    }
    foreach ($baseline as $key => $expected) {
        $record('baseline_'.strtolower($key), $is($key, $expected));
    }
    if ($profile === 'staging') {
        foreach ($testSwitches as $key) {
            $record('staging_boolean_'.strtolower($key), $is($key, 'true') || $is($key, 'false'));
        }
        // Shapes only, never values: a blank credential keeps its path unavailable.
        $shape = static fn (string $key, string $pattern): bool => $value($key) === '' || preg_match($pattern, $value($key)) === 1;
        $record('staging_stripe_test_secret_key_shape', $shape('STRIPE_TEST_SECRET_KEY', '/\Ask_test_[A-Za-z0-9]{8,200}\z/D'));
        $record('staging_stripe_account_shape', $shape('STRIPE_ACCOUNT_ID', '/\Aacct_[A-Za-z0-9]{1,64}\z/D'));
        $record('staging_stripe_webhook_secret_shape', $shape('STRIPE_WEBHOOK_SECRET', '/\Awhsec_[A-Za-z0-9]{8,200}\z/D'));
        $live = false;
        foreach ($env as $candidate) {
            $live = $live || preg_match('/(?:sk|rk)_live_/', (string) $candidate) === 1;
        }
        $record('staging_no_live_provider_credential', ! $live);
        // Production checkout refuses test funds outside local/testing and live funds in staging.
        $record('staging_no_production_funds_mode', $value('PRODUCTION_CHECKOUT_FUNDS_MODE') === '');
    }
    $record('queue_retry_exceeds_media_timeout_and_lease', ctype_digit($value('DB_QUEUE_RETRY_AFTER'))
        && (int) $value('DB_QUEUE_RETRY_AFTER') > 960);
    foreach (['DB_URL', 'DATABASE_URL', 'APP_CONFIG_CACHE', 'LARAVEL_STORAGE_PATH', 'DB_QUEUE_CONNECTION', 'SESSION_CONNECTION'] as $key) {
        $record('no_unreviewed_'.strtolower($key), $value($key) === '');
    }
    $reserved = [
        'CONTACT_INQUIRIES_PRIVACY_NOTICE', 'CONTACT_INQUIRIES_RETENTION_REFERENCE', 'CONTACT_INQUIRIES_OPERATOR_ID',
        'VASEY_TEST_PRICING_POLICY', 'VASEY_TEST_PROMOTIONS', 'VASEY_TEST_INVENTORY_POLICY',
        'VASEY_TEST_EXCLUSIVE_SELECTION_POLICY', 'VASEY_TEST_ORDER_POLICY', 'VASEY_TEST_CHECKOUT_POLICY',
        'STRIPE_ACCOUNT_ID', 'STRIPE_WEBHOOK_SECRET', 'STRIPE_TEST_SECRET_KEY', 'STRIPE_TEST_FINALIZATION_POLICY',
        'VASEY_TEST_CONTRACT_ISSUANCE_POLICY', 'VASEY_TEST_FULFILLMENT_ACTIVATION_POLICY', 'VASEY_TEST_DELIVERY_ACCESS_POLICY',
    ];
    if ($profile === 'production') {
        foreach ($reserved as $key) {
            $record('inactive_'.strtolower($key), $value($key) === '');
        }
    }
    $required = ['APP_URL', 'APP_KEY', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
        'MEDIA_FFMPEG', 'MEDIA_FFPROBE', 'MEDIA_PRLIMIT', 'MEDIA_CLAMSCAN', 'MEDIA_TAG_PATH', 'MEDIA_TAG_SHA256'];
    if ($mode === 'template') {
        foreach (array_merge($required, ['DB_SOCKET', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS']) as $key) {
            $record('template_blank_'.strtolower($key), $is($key, ''));
        }
        $finish();
    }

    foreach (array_diff($required, ['DB_HOST']) as $key) {
        $record('configured_'.strtolower($key), trim($value($key)) !== '' && ! str_contains($value($key), '${'));
    }
    $url = parse_url($value('APP_URL'));
    $record('app_https_origin', filter_var($value('APP_URL'), FILTER_VALIDATE_URL) !== false
        && is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host'])
        && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
        && in_array($url['path'] ?? '', ['', '/'], true));
    $key = $value('APP_KEY');
    $decoded = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
    $record('application_key_shape_only', is_string($decoded) && strlen($decoded) === 32);
    $record('database_endpoint_configured', trim($value('DB_HOST')) !== '' || str_starts_with($value('DB_SOCKET'), '/'));
    $record('database_port_valid', ctype_digit($value('DB_PORT')) && (int) $value('DB_PORT') > 0 && (int) $value('DB_PORT') <= 65535);
    $record('database_nonroot_identity_named', trim($value('DB_USERNAME')) !== '' && strtolower($value('DB_USERNAME')) !== 'root');
    $tag = $value('MEDIA_TAG_PATH');
    $record('media_tag_relative_path', $tag !== '' && ! str_starts_with($tag, '/') && ! str_contains($tag, '\\')
        && ! preg_match('/[\x00-\x1f\x7f]/', $tag) && count(array_intersect(explode('/', $tag), ['', '.', '..'])) === 0);
    $record('media_tag_hash_shape_only', preg_match('/\A[0-9a-f]{64}\z/', $value('MEDIA_TAG_SHA256')) === 1);
    foreach (['MEDIA_FFMPEG', 'MEDIA_FFPROBE', 'MEDIA_PRLIMIT', 'MEDIA_CLAMSCAN'] as $tool) {
        $record('absolute_'.strtolower($tool), str_starts_with($value($tool), '/') && ! preg_match('/[\x00-\x1f\x7f]/', $value($tool)));
    }

    if ($runtime) {
        $record('runtime_linux', PHP_OS_FAMILY === 'Linux');
        $record('runtime_php_84', PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4);
        foreach (['pdo_mysql', 'pdo_sqlite', 'mbstring', 'intl', 'bcmath', 'gd', 'fileinfo', 'zip', 'curl', 'posix', 'pcntl', 'dom', 'xml', 'xmlwriter'] as $extension) {
            $record('runtime_extension_'.$extension, extension_loaded($extension));
        }
        $record('runtime_nonroot_identity', function_exists('posix_geteuid') && posix_geteuid() !== 0);
        foreach (['MEDIA_FFMPEG', 'MEDIA_FFPROBE', 'MEDIA_PRLIMIT', 'MEDIA_CLAMSCAN'] as $tool) {
            $record('runtime_executable_'.strtolower($tool), is_file($value($tool)) && is_executable($value($tool)));
        }
        $private = $root.'/storage/app/private';
        $record('runtime_private_root_canonical', $canonical($private) && is_dir($private));
        $record('runtime_private_root_mode_and_access', is_dir($private) && (fileperms($private) & 0777) === 0700
            && is_readable($private) && is_writable($private));
        // Presence is information, not a reason to delete a live config cache.
        $checks[] = ['id' => 'runtime_config_cache', 'status' => is_file($root.'/bootstrap/cache/config.php') ? 'present_unverified' : 'absent'];
    }
    $finish();
} catch (Throwable) {
    $record('inspection_completed_without_error', false);
    $finish();
}
