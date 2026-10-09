<?php

declare(strict_types=1);

// Run with an empty application environment. No values, exceptions or provider responses are printed.
// Usage: php validate-runtime.php ENV_FILE EXPECTED_ENV HOST DATABASE [PREVIOUS_ENV_FILE]
use App\Support\PhpCliBinary;
use App\Support\PhpCliBinaryUnavailable;
use Dotenv\Dotenv;
use Dotenv\Parser\Parser;
use Illuminate\Contracts\Console\Kernel;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
if ($argc < 5 || $argc > 6) {
    fwrite(STDERR, "FAIL runtime.arguments\n");
    exit(2);
}
[$script, $file, $expected, $host, $database] = $argv;
$failures = [];
$check = static function (string $id, bool $ok) use (&$failures): void {
    if (! $ok) {
        $failures[] = $id;
    }
};
$scratch = null;
try {
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    if (! is_file($file) || is_link($file) || ! is_readable($file) || filesize($file) > 262144) {
        throw new RuntimeException;
    }
    $raw = file_get_contents($file);
    $entries = (new Parser)->parse($raw);
    $names = array_map(static fn ($entry) => $entry->getName(), $entries);
    $check('runtime.unique_keys', count($names) === count(array_unique($names)));
    $values = Dotenv::parse($raw);
    $check('runtime.no_live_keys', ! preg_match('/(?:sk|rk|pk)_live_/', implode("\n", array_filter($values, 'is_string'))));
    $check('runtime.no_cache_override', array_filter($names, static fn ($name) => preg_match('/\AAPP_[A-Z_]*CACHE\z/', $name)) === []);
    if (isset($argv[5])) {
        $previous = $argv[5];
        if (! is_file($previous) || is_link($previous) || filesize($previous) > 262144) {
            throw new RuntimeException;
        }
        $previousValues = Dotenv::parse(file_get_contents($previous));
        $check('runtime.key_custody', isset($values['APP_KEY'], $previousValues['APP_KEY'])
            && hash_equals($previousValues['APP_KEY'], $values['APP_KEY']));
    }
    if ($failures === []) {
        $scratch = sys_get_temp_dir().'/vasey-runtime-'.bin2hex(random_bytes(16));
        if (! mkdir($scratch, 0700) || file_put_contents($scratch.'/runtime.env', $raw) !== strlen($raw)) {
            throw new RuntimeException;
        }
        chmod($scratch.'/runtime.env', 0600);
        putenv('APP_CONFIG_CACHE='.$scratch.'/absent-config.php');
        $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $scratch.'/absent-config.php';
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($scratch)->loadEnvironmentFrom('runtime.env');
        $app->make(Kernel::class)->bootstrap();
        $check('runtime.environment', in_array($expected, ['local', 'staging'], true) && app()->environment() === $expected);
        $check('runtime.debug', config('app.debug') === false);
        $check('runtime.origin', config('app.url') === 'https://'.$host);
        $check('runtime.key_shape', is_string(config('app.key')) && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/', config('app.key')) === 1);
        $check('runtime.session', config('session.driver') === 'database' && config('session.secure') === true && config('session.http_only') === true);
        $check('runtime.cache', config('cache.default') === 'database');
        $check('runtime.queue', config('queue.default') === 'database' && config('queue.connections.database.retry_after') >= 1200);
        $db = config('database.connections.mysql');
        $check('runtime.database', config('database.default') === 'mysql' && $db['host'] === '127.0.0.1'
            && $db['database'] === $database && $db['username'] === 'vasey_app' && is_string($db['password']) && $db['password'] !== ''
            && empty($db['url']) && empty($db['unix_socket']));
        $check('runtime.filesystem', config('filesystems.default') === 'local');
        $check('runtime.mail', config('mail.default') === 'log');
        $check('runtime.maintenance', config('app.maintenance.driver') === 'file');
        $check('runtime.stripe_mode', config('payments.stripe.mode') === 'test');
        foreach (['http_enabled', 'fresh_checkout_enabled', 'reconciliation_enabled', 'provider_io_enabled',
            'exemption_authoring_enabled', 'committed_read_receipts_enabled'] as $flag) {
            $check('runtime.production_'.$flag, config('production_checkout.'.$flag) === false);
        }
        $check('runtime.production_credentials', in_array(config('production_checkout.secret_key'), [null, ''], true)
            && in_array(config('production_checkout.funds_mode'), [null, ''], true));
        // Renderer children started inside FPM requests need a genuine CLI PHP of this exact version and build (M-16).
        // Validate the configured binary as the FPM pool will, with a real probe; the path itself is never printed.
        try {
            (new PhpCliBinary(config('app.php_cli_binary'), 'fpm-fcgi'))->path();
            $check('runtime.php_cli_binary', true);
        } catch (PhpCliBinaryUnavailable) {
            $check('runtime.php_cli_binary', false);
        }
    }
} catch (Throwable) {
    $failures[] = 'runtime.unavailable';
} finally {
    if ($scratch !== null) {
        @unlink($scratch.'/runtime.env');
        @rmdir($scratch);
    }
}
foreach (array_unique($failures) as $id) {
    fwrite(STDERR, 'FAIL '.$id.PHP_EOL);
}
if ($failures !== []) {
    exit(1);
}
echo "PASS runtime.profile (isolated file, real configuration, no provider request)\n";
