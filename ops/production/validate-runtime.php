<?php

declare(strict_types=1);

// Production runtime admission. Run with an empty application environment, as the application user, from a release.
// No values, exceptions or provider responses are printed: only fixed check identifiers on failure.
//
// Usage: php validate-runtime.php ENV_FILE production HOST DATABASE [PREVIOUS_ENV_FILE] [--runtime]
//
// It replaces the staging validator's test-mode requirements and reuses the reviewed private-server preflight instead:
//   1. scripts/ops/private-server-preflight.php --env-file ENV_FILE --profile=production [--runtime] must report
//      FILE_CHECKS_PASSED. That is the one reviewed definition of the production file baseline: LOG_CHANNEL=stderr, every
//      test-commerce switch and reserved credential off or blank, database/queue/session/cache settings, absolute media tool
//      paths, a 32-byte key, no unreviewed precedence overrides. Its identifiers come back as `preflight.<id>`.
//      `--runtime` adds the preflight's host checks (PHP 8.4 and extensions, nonroot, executables, a 0700 private root), so
//      pass it only for a release whose storage/app/private is attached, never during the build before attachment.
//   2. the same file is loaded through the real Laravel configuration in an isolated scratch directory, with no provider
//      request, and the effective values must agree with what the kit installs: APP_ENV production, APP_DEBUG false, the
//      https origin, key shape, database sessions/cache/queue, the local MySQL account, local private storage, file
//      maintenance and the stderr log channel.
//   3. production holds: no live-looking Stripe credential anywhere, every PRODUCTION_CHECKOUT_* switch and credential
//      off or blank, and the four pre-launch capability flags in PRE_LAUNCH_HOLDS false. Production checkout is a code and
//      review gate, not an environment switch (docs/ops/production-activation-packet.md); turning it on, or lifting one of the
//      four flags, is a reviewed change to this file together with Sean's explicit authorization.
//   4. APP_KEY equals the key of the previous (served) environment, when one is given.
//
// Admissions (lane PCC). MAIL_MAILER and STRIPE_MODE are the preflight's: `log` and `test` unless the candidate file sets
// VASEY_PRODUCTION_REAL_MAIL_ADMITTED or VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED to exactly `true` (then `smtp` with implicit TLS,
// and `live`). This file adds only what the live flag changes in its holds: an `sk_live_` value is admitted as
// PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY and nowhere else, and PRODUCTION_CHECKOUT_FUNDS_MODE may be blank or `live`. Every
// PRODUCTION_CHECKOUT_*_ENABLED switch stays held false whatever the flags say: admitting live credentials is not activation.
// The effective admissions (config/production-runtime.php) must agree with the file.
use App\Support\Http\TrustedProxyList;
use App\Support\PhpCliBinary;
use App\Support\PhpCliBinaryUnavailable;
use Dotenv\Dotenv;
use Dotenv\Parser\Parser;
use Illuminate\Contracts\Console\Kernel;
use Vasey\Ops\CapabilityBoundary;

/**
 * Pre-launch capability hold, approved by Sean on 2026-10-18: the environment keys of the four flags, each refused unless false.
 * Three have no environment gate in the application: an operative free grant and an operator notification are bound only by an
 * adapter that other code would supply, and the public track sitemap build chain is not environment-gated while the public index
 * serves it in production. In production this validator is their only guard. The fourth, V2 listening promotion, is reached
 * today only through a customer session, which the test customer access path creates in local, testing and staging only, and
 * the production listening lane does not read it; it is held as defence in depth because promotion is a one-way data change.
 * Lifting one is a reviewed change to this file together with Sean's authorization for that activation. The config key of each
 * flag is not restated here: it is read from scripts/ops/capability-boundary.php, the definition shared with the staging deploy
 * gate and the profile validator, so a renamed key cannot leave this gate checking a stale one. A key the boundary no longer
 * holds fails closed (runtime.unavailable). Never printed with a value.
 */
const PRE_LAUNCH_HOLDS = [
    'VASEY_OPERATIVE_FREE_GRANTS_ENABLED',
    'CONTACT_INQUIRY_OPERATOR_NOTIFICATIONS_ENABLED',
    'DISCOVERY_SITEMAP_ENABLED',
    'VASEY_LISTENING_V2_PROMOTION_ENABLED',
];

ini_set('display_errors', '0');
ini_set('log_errors', '0');
$arguments = array_slice($argv, 1);
$hostRuntime = false;
if ($arguments !== [] && end($arguments) === '--runtime') {
    $hostRuntime = true;
    array_pop($arguments);
}
if (count($arguments) < 4 || count($arguments) > 5) {
    fwrite(STDERR, "FAIL runtime.arguments\n");
    exit(2);
}
[$file, $expected, $host, $database] = $arguments;
$failures = [];
$check = static function (string $id, bool $ok) use (&$failures): void {
    if (! $ok) {
        $failures[] = $id;
    }
};
$scratch = null;
try {
    $root = dirname(__DIR__, 2);
    require $root.'/vendor/autoload.php';
    // The shared capability boundary: a missing file is a refusal (runtime.unavailable), never a silent skip, because this
    // validator is the only guard some of the held flags have in production.
    $boundary = $root.'/scripts/ops/capability-boundary.php';
    if (! is_file($boundary) || is_link($boundary)) {
        throw new RuntimeException;
    }
    require_once $boundary;
    $holdKeys = array_intersect_key(CapabilityBoundary::stagedFlags(), array_flip(PRE_LAUNCH_HOLDS));
    if (count($holdKeys) !== count(PRE_LAUNCH_HOLDS)) {
        throw new RuntimeException;
    }
    // The preflight demands a canonical absolute path itself; refuse anything else before it is asked.
    $check('runtime.arguments_shape', $expected === 'production' && str_starts_with($file, '/')
        && preg_match('/\A[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+\z/', $host) === 1
        && preg_match('/\A[a-z][a-z0-9_]{0,40}\z/', $database) === 1);
    if ($failures !== []) {
        throw new RuntimeException;
    }
    if (! is_file($file) || is_link($file) || ! is_readable($file) || filesize($file) > 131072) {
        throw new RuntimeException;
    }
    $raw = file_get_contents($file);
    $entries = (new Parser)->parse($raw);
    $names = array_map(static fn ($entry) => $entry->getName(), $entries);
    $check('runtime.unique_keys', count($names) === count(array_unique($names)));
    $values = Dotenv::parse($raw);
    // Admissions: exact `true` only. The preflight child below refuses any value other than `true` or `false` by identifier.
    $realMail = ($values['VASEY_PRODUCTION_REAL_MAIL_ADMITTED'] ?? null) === 'true';
    $liveStripe = ($values['VASEY_PRODUCTION_LIVE_STRIPE_ADMITTED'] ?? null) === 'true';
    $liveSecret = static fn (mixed $value): bool => is_string($value) && preg_match('/\Ask_live_[A-Za-z0-9]{1,240}\z/D', $value) === 1;
    $scanned = $values;
    if ($liveStripe && $liveSecret($scanned['PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY'] ?? null)) {
        // The one admitted place for a live key. Restricted (rk_) and publishable (pk_) keys stay refused everywhere.
        unset($scanned['PRODUCTION_CHECKOUT_STRIPE_SECRET_KEY']);
    }
    $check('holds.no_live_provider_credential', ! preg_match('/(?:sk|rk|pk)_live_/', implode("\n", array_filter($scanned, 'is_string'))));
    $check('runtime.no_cache_override', array_filter($names, static fn ($name) => preg_match('/\AAPP_[A-Z_]*CACHE\z/', $name)) === []);
    // A production-checkout switch is accepted only as the literal false the template ships, or unset. A blank assignment is
    // not the same as unset (the application's env() default does not apply to it), and the configuration check below refuses it.
    foreach ($values as $name => $value) {
        if (preg_match('/\APRODUCTION_CHECKOUT_[A-Z_]*ENABLED\z/', (string) $name) === 1) {
            $check('holds.'.strtolower((string) $name), in_array(strtolower((string) $value), ['false', '(false)'], true));
        }
    }
    // The pre-launch capability flags follow the same rule: only the literal false, or unset (the config default is false).
    // A blank or any other value fails here, before the preflight child and the configuration check below are asked.
    foreach (PRE_LAUNCH_HOLDS as $name) {
        if (array_key_exists($name, $values)) {
            $check('holds.'.strtolower($name), in_array(strtolower((string) $values[$name]), ['false', '(false)'], true));
        }
    }
    if (isset($arguments[4])) {
        $previous = $arguments[4];
        if (! is_file($previous) || is_link($previous) || filesize($previous) > 262144) {
            throw new RuntimeException;
        }
        $previousValues = Dotenv::parse(file_get_contents($previous));
        $check('runtime.key_custody', isset($values['APP_KEY'], $previousValues['APP_KEY'])
            && hash_equals($previousValues['APP_KEY'], $values['APP_KEY']));
    }

    // 1. The reviewed file baseline, as a child process with an empty environment. Only identifiers are taken from it.
    if ($failures === []) {
        $command = [PHP_BINARY, $root.'/scripts/ops/private-server-preflight.php', '--env-file', $file, '--profile=production'];
        if ($hostRuntime) {
            $command[] = '--runtime';
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root, ['PATH' => '/usr/local/bin:/usr/bin:/bin', 'LC_ALL' => 'C']);
        if (! is_resource($process)) {
            throw new RuntimeException;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 60;
        do {
            $output .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(20000);
            }
        } while ($status['running'] && microtime(true) < $deadline);
        if ($status['running']) {
            proc_terminate($process, 9);
            proc_close($process);
            throw new RuntimeException;
        }
        $output .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $report = json_decode($output, true);
        if (! is_array($report) || ($report['profile'] ?? null) !== 'production' || ! is_array($report['checks'] ?? null)) {
            throw new RuntimeException;
        }
        $check('preflight.result', ($report['result'] ?? null) === 'FILE_CHECKS_PASSED');
        foreach ($report['checks'] as $item) {
            if (is_array($item) && ($item['status'] ?? null) === 'blocked' && is_string($item['id'] ?? null)
                && preg_match('/\A[a-z0-9_]{1,80}\z/', $item['id']) === 1) {
                $failures[] = 'preflight.'.$item['id'];
            }
        }
    }

    // 2 and 3. The effective configuration, in an isolated scratch directory, without any provider request.
    if ($failures === []) {
        $scratch = sys_get_temp_dir().'/vasey-runtime-'.bin2hex(random_bytes(16));
        if (! mkdir($scratch, 0700) || file_put_contents($scratch.'/runtime.env', $raw) !== strlen($raw)) {
            throw new RuntimeException;
        }
        chmod($scratch.'/runtime.env', 0600);
        putenv('APP_CONFIG_CACHE='.$scratch.'/absent-config.php');
        $_ENV['APP_CONFIG_CACHE'] = $_SERVER['APP_CONFIG_CACHE'] = $scratch.'/absent-config.php';
        $app = require $root.'/bootstrap/app.php';
        $app->useEnvironmentPath($scratch)->loadEnvironmentFrom('runtime.env');
        $app->make(Kernel::class)->bootstrap();
        $check('runtime.environment', app()->environment() === 'production');
        $check('runtime.debug', config('app.debug') === false);
        $check('runtime.origin', config('app.url') === 'https://'.$host);
        $check('runtime.key_shape', is_string(config('app.key')) && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/', config('app.key')) === 1);
        $check('runtime.session', config('session.driver') === 'database' && config('session.secure') === true && config('session.http_only') === true);
        $check('runtime.cache', config('cache.default') === 'database');
        $check('runtime.queue', config('queue.default') === 'database' && config('queue.connections.database.retry_after') >= 1200);
        $db = config('database.connections.mysql');
        // The kit's topology is a local MySQL: every trigger DEFINER is vasey_app@127.0.0.1. A managed database needs a reviewed change.
        $check('runtime.database', config('database.default') === 'mysql' && $db['host'] === '127.0.0.1'
            && $db['database'] === $database && $db['username'] === 'vasey_app' && is_string($db['password']) && $db['password'] !== ''
            && empty($db['url']) && empty($db['unix_socket']));
        $check('runtime.filesystem', config('filesystems.default') === 'local');
        $check('runtime.maintenance', config('app.maintenance.driver') === 'file');
        $check('runtime.log_channel', config('logging.default') === 'stderr');
        // Proxy trust is an explicit, bounded list or none; a wildcard or malformed list is refused here (the middleware trusts none).
        $check('runtime.trusted_proxies', TrustedProxyList::parse(config('trusted-proxies.proxies')) !== null);
        foreach (['http_enabled', 'fresh_checkout_enabled', 'reconciliation_enabled', 'provider_io_enabled',
            'exemption_authoring_enabled', 'committed_read_receipts_enabled'] as $flag) {
            $check('holds.production_checkout_'.$flag, config('production_checkout.'.$flag) === false);
        }
        $check('holds.production_credentials', $liveStripe
            ? (in_array(config('production_checkout.secret_key'), [null, ''], true) || $liveSecret(config('production_checkout.secret_key')))
                && in_array(config('production_checkout.funds_mode'), [null, '', 'live'], true)
            : in_array(config('production_checkout.secret_key'), [null, ''], true)
                && in_array(config('production_checkout.funds_mode'), [null, ''], true));
        // Production identity SMTP composes settings only from its reviewed code literal, and only with real mail admitted.
        $check('holds.production_identity_smtp', $realMail || config('production-identity-smtp.settings') === null);
        // The admissions as the application reads them must be the file's. MAIL_MAILER and STRIPE_MODE stay the preflight's.
        $check('runtime.production_admissions', config('production-runtime.real_mail_admitted') === $realMail
            && config('production-runtime.live_stripe_admitted') === $liveStripe);
        // Strictly false, on purpose: a config key that is renamed or removed reads null and fails closed rather than passing.
        foreach ($holdKeys as $name => $key) {
            $check('holds.'.strtolower($name), config($key) === false);
        }
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
echo 'PASS runtime.profile (production: reviewed preflight baseline'.($hostRuntime ? ' with host checks' : '').', isolated real configuration, production holds, no provider request)'.PHP_EOL;
