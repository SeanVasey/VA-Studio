<?php

// This guarded console/router belongs only to persistent-content.mjs, not application routes.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

final class PersistentContentBootstrap
{
    private const ORIGIN = 'http://127.0.0.1:8175';

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Persistent workspace safety check failed.');
        }
    }

    private static function canonical(string $path): bool
    {
        if (! str_starts_with($path, '/') || preg_match('/[\x00-\x1f\x7f]/', $path)) {
            return false;
        }
        $cursor = '';
        foreach (explode('/', substr($path, 1)) as $part) {
            if (in_array($part, ['', '.', '..'], true)) {
                return false;
            }
            $cursor .= '/'.$part;
            if (is_link($cursor)) {
                return false;
            }
        }

        return realpath($path) === $path;
    }

    private static function owned(string $path): bool
    {
        return ! function_exists('posix_geteuid') || fileowner($path) === posix_geteuid();
    }

    private static function safeAncestors(string $directory): bool
    {
        $parent = dirname($directory);
        if (! self::canonical($parent) || ! self::owned($parent) || (fileperms($parent) & 0022) !== 0) {
            return false;
        }
        $cursor = '/';
        foreach (['', ...explode('/', substr($parent, 1))] as $part) {
            if ($part !== '') {
                $cursor = rtrim($cursor, '/').'/'.$part;
            }
            if (! is_dir($cursor) || ((fileperms($cursor) & 0022) !== 0
                && ! (fileowner($cursor) === 0 && (fileperms($cursor) & 01000) !== 0))) {
                return false;
            }
        }

        return true;
    }

    private static function file(string $path, int $maximum = 16384): void
    {
        self::require(self::canonical($path) && is_file($path) && self::owned($path));
        $stat = lstat($path);
        self::require(($stat['mode'] & 07777) === 0600 && $stat['nlink'] === 1 && $stat['size'] <= $maximum);
    }

    private static function schemaHash(string $checkout): string
    {
        $hash = hash_init('sha256');
        $files = glob($checkout.'/database/migrations/*.php');
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            hash_update($hash, basename($file)."\0".file_get_contents($file)."\0");
        }
        hash_update($hash, file_get_contents($checkout.'/composer.lock'));
        hash_update($hash, file_get_contents($checkout.'/public/build/manifest.json'));

        return hash_final($hash);
    }

    /** Validate the retained identity before any application boot, read or mutation. */
    public static function guard(array $states, bool $requireLease = true): array
    {
        clearstatcache();
        $directory = getenv('VASEY_CONTENT_DIRECTORY');
        $checkout = realpath(__DIR__.'/../..');
        self::require(self::canonical($checkout.'/public/build') && self::canonical($checkout.'/public/build/manifest.json')
            && is_dir($checkout.'/public/build') && is_file($checkout.'/public/build/manifest.json'));
        self::require(is_string($directory) && self::canonical($directory) && is_dir($directory)
            && self::safeAncestors($directory)
            && self::owned($directory) && (fileperms($directory) & 07777) === 0700
            && $directory !== $checkout && ! str_starts_with($directory, $checkout.'/') && ! str_starts_with($checkout, $directory.'/'));
        foreach (['app', 'app/private', 'framework', 'framework/views', 'framework/sessions', 'framework/cache', 'framework/cache/data', 'logs', 'public', 'tmp'] as $child) {
            $path = $directory.'/'.$child;
            self::require(self::canonical($path) && is_dir($path) && self::owned($path) && (fileperms($path) & 07777) === 0700);
        }
        self::file($directory.'/identity.json');
        self::file($directory.'/lease');
        self::file($directory.'/database.sqlite', PHP_INT_MAX);
        self::require(glob($directory.'/.env*') === []);
        foreach (['config', 'routes', 'events'] as $cache) {
            self::require(! file_exists($directory.'/'.$cache.'.php') && ! is_link($directory.'/'.$cache.'.php'));
        }
        foreach (['packages', 'services'] as $cache) {
            if (file_exists($directory.'/'.$cache.'.php') || is_link($directory.'/'.$cache.'.php')) {
                self::file($directory.'/'.$cache.'.php', 524288);
            }
        }
        $identity = json_decode(file_get_contents($directory.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
        $keys = array_keys($identity);
        sort($keys);
        self::require($keys === ['app_key', 'checkout', 'directory', 'directory_identity', 'installation_id', 'origin', 'schema_hash', 'schema_version', 'session_cookie', 'state']);
        $stat = lstat($directory);
        self::require($identity['schema_version'] === 1 && $identity['origin'] === self::ORIGIN
            && $identity['directory'] === $directory && $identity['checkout'] === $checkout
            && $identity['directory_identity'] === ['dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino']]
            && in_array($identity['state'], $states, true) && $identity['schema_hash'] === self::schemaHash($checkout)
            && preg_match('/\A[a-f0-9]{64}\z/D', $identity['installation_id']) === 1
            && preg_match('/\Abase64:[A-Za-z0-9+\/]{43}=\z/D', $identity['app_key']) === 1
            && strlen(base64_decode(substr($identity['app_key'], 7), true)) === 32
            && preg_match('/\Avasey_content_[a-f0-9]{16}\z/D', $identity['session_cookie']) === 1);
        foreach (['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => self::ORIGIN,
            'APP_KEY' => $identity['app_key'], 'APP_PREVIOUS_KEYS' => '',
            'VASEY_CONTENT_ID' => $identity['installation_id'], 'VASEY_CONTENT_CHECKOUT' => $checkout,
            'VASEY_CONTENT_SCHEMA_HASH' => $identity['schema_hash'],
            'LARAVEL_STORAGE_PATH' => $directory, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite',
            'DB_URL' => '', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => $identity['session_cookie'],
            'SESSION_ENCRYPT' => 'true', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_HTTP_ONLY' => 'true',
            'SESSION_SAME_SITE' => 'strict', 'SESSION_DOMAIN' => 'null',
            'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database', 'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local'] as $key => $value) {
            self::require(getenv($key) === $value);
        }
        foreach (['CONFIG' => 'config', 'ROUTES' => 'routes', 'EVENTS' => 'events', 'PACKAGES' => 'packages', 'SERVICES' => 'services'] as $key => $file) {
            self::require(getenv('APP_'.$key.'_CACHE') === $directory.'/'.$file.'.php');
        }
        foreach (['STRIPE_WEBHOOK_ENABLED', 'STRIPE_TEST_CHECKOUT_ENABLED', 'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED',
            'STRIPE_TEST_FINALIZATION_ENABLED', 'VASEY_TEST_CONTRACT_ISSUANCE_ENABLED', 'VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED',
            'VASEY_TEST_DELIVERY_ACCESS_ENABLED', 'VASEY_TEST_PURCHASE_CLAIMS_ENABLED', 'VASEY_TEST_CUSTOMER_ACCOUNTS_ENABLED',
            'VASEY_TEST_CUSTOMER_IDENTITY_ENABLED', 'CONTACT_INQUIRIES_ENABLED', 'CONTACT_TEST_ORDER_INQUIRIES_ENABLED'] as $key) {
            self::require(getenv($key) === 'false');
        }
        foreach (['STRIPE_ACCOUNT_ID', 'STRIPE_TEST_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'MEDIA_TAG_PATH', 'MEDIA_TAG_SHA256'] as $key) {
            self::require(getenv($key) === '');
        }
        foreach (['ffmpeg', 'ffprobe', 'prlimit', 'clamscan'] as $tool) {
            self::require(getenv('MEDIA_'.strtoupper($tool)) === $directory.'/disabled-'.$tool
                && ! file_exists($directory.'/disabled-'.$tool) && ! is_link($directory.'/disabled-'.$tool));
        }
        $lease = getenv('VASEY_CONTENT_LEASE');
        self::require(is_string($lease) && preg_match('/\A[a-f0-9]{64}\z/D', $lease) === 1);
        if ($requireLease) {
            self::require(hash_equals($lease, file_get_contents($directory.'/lease')));
            $handle = fopen($directory.'/lease', 'r+');
            try {
                // A retained token alone is insufficient: the broker must still own the OS lease.
                $unlocked = flock($handle, LOCK_EX | LOCK_NB);
                if ($unlocked) {
                    flock($handle, LOCK_UN);
                }
                self::require(! $unlocked);
            } finally {
                fclose($handle);
            }
        }

        return [$directory, $identity];
    }

    public static function lease(): void
    {
        [$directory] = self::guard(['initializing', 'ready'], false);
        $handle = fopen($directory.'/lease', 'r+');
        self::require(flock($handle, LOCK_EX | LOCK_NB));
        try {
            self::require(ftruncate($handle, 0) && fwrite($handle, getenv('VASEY_CONTENT_LEASE')) === 64 && fflush($handle));
            echo "LEASED\n";
            fflush(STDOUT);
            while (! feof(STDIN)) {
                fread(STDIN, 1024);
            }
        } finally {
            ftruncate($handle, 0);
            fflush($handle);
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function application(string $directory): Application
    {
        require_once __DIR__.'/../../vendor/autoload.php';
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->useEnvironmentPath($directory);
        $app->useStoragePath($directory);
        $app->usePublicPath($directory.'/public');
        $app->afterBootstrapping(BootProviders::class, function ($app) use ($directory): void {
            self::require($app->environment('local') && config('app.debug') === false && config('app.url') === self::ORIGIN
                && config('app.key') === getenv('APP_KEY') && config('app.previous_keys') === []
                && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $directory.'/database.sqlite'
                && ! config('database.connections.sqlite.url') && storage_path() === $directory && public_path() === $directory.'/public'
                && config('filesystems.default') === 'local' && config('filesystems.disks.local.root') === $directory.'/app/private'
                && config('filesystems.disks.local.serve') === false && config('mail.default') === 'array'
                && config('queue.default') === 'database' && config('cache.default') === 'file'
                && config('session.driver') === 'file' && config('session.encrypt') === true
                && config('session.http_only') === true && config('session.same_site') === 'strict' && config('session.secure') === false
                && config('session.domain') === null && config('session.cookie') === getenv('SESSION_COOKIE')
                && config('session.files') === $directory.'/framework/sessions' && config('view.compiled') === $directory.'/framework/views');
            foreach (['payments.stripe.webhook_enabled', 'payments.stripe.checkout_enabled', 'payments.stripe.processing_enabled',
                'payments.stripe.finalization_enabled', 'contracts.test_issuance_enabled', 'delivery.test_activation_enabled',
                'delivery.test_access_enabled', 'customer.test_purchase_claims_enabled', 'customer.test_accounts_enabled',
                'customer.test_identity_enabled', 'inquiries.enabled', 'inquiries.test_order_inquiries_enabled'] as $key) {
                self::require(config($key) === false);
            }
            foreach (['commerce.test_pricing_policy', 'commerce.test_promotions', 'commerce.test_inventory_policy',
                'commerce.test_exclusive_selection_policy', 'commerce.test_order_policy', 'commerce.test_checkout_policy',
                'payments.stripe.finalization_policy', 'contracts.test_issuance_policy', 'delivery.test_activation_policy',
                'delivery.test_access_policy', 'customer.identity_transport', 'payments.stripe.secret_key',
                'payments.stripe.webhook_secret', 'payments.stripe.account_id', 'inquiries.privacy_notice',
                'inquiries.retention_policy_reference', 'inquiries.operator_user_id'] as $key) {
                self::require(in_array(config($key), [null, ''], true));
            }
            foreach (['ffmpeg', 'ffprobe', 'prlimit', 'clamscan'] as $tool) {
                self::require(config('media.'.$tool) === $directory.'/disabled-'.$tool);
            }
        });

        return $app;
    }

    private static function copyBuild(string $source, string $target): void
    {
        self::require(self::canonical($source) && is_dir($source));
        self::require(mkdir($target, 0700));
        foreach (scandir($source) as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $path = $source.'/'.$name;
            self::require(self::canonical($path));
            if (is_dir($path)) {
                self::copyBuild($path, $target.'/'.$name);
            } else {
                self::require(is_file($path) && copy($path, $target.'/'.$name) && chmod($target.'/'.$name, 0600));
            }
        }
    }

    private static function secureInitialFiles(string $directory): void
    {
        // Framework cache/asset writers use owner-executable modes on a first install.
        // Tighten only this newly created, leased installation; never repair an existing tree on start.
        foreach (scandir($directory) as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $path = $directory.'/'.$name;
            self::require(self::canonical($path) && self::owned($path));
            if (is_dir($path)) {
                self::require(chmod($path, 0700));
                self::secureInitialFiles($path);
            } else {
                self::require(is_file($path) && lstat($path)['nlink'] === 1 && chmod($path, 0600));
            }
        }
    }

    public static function initialize(): void
    {
        [$directory, $identity] = self::guard(['initializing']);
        self::require(filesize($directory.'/database.sqlite') === 0 && ! file_exists($directory.'/public/build'));
        $app = self::application($directory);
        $app->make(Kernel::class)->bootstrap();
        self::require(Artisan::call('filament:assets', ['--no-interaction' => true]) === 0);
        self::copyBuild($identity['checkout'].'/public/build', $directory.'/public/build');
        self::require(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0);
        self::require(DB::table('users')->count() === 0 && DB::table('tracks')->count() === 0
            && DB::table('license_templates')->count() === 0 && DB::table('orders')->count() === 0);
        self::secureInitialFiles($directory);
        self::guard(['initializing']);
        $identity['state'] = 'ready';
        self::require(file_put_contents($directory.'/identity.json', json_encode($identity, JSON_THROW_ON_ERROR), LOCK_EX) !== false);
    }

    public static function verify(): void
    {
        [$directory] = self::guard(['ready']);
        $app = self::application($directory);
        $app->make(Kernel::class)->bootstrap();
        // Read-only boot: no migrations, reseeding, account resets or key changes.
        self::require(DB::connection()->getDriverName() === 'sqlite' && DB::table('migrations')->count() > 0);
    }

    public static function operator(): int
    {
        [$directory] = self::guard(['ready']);
        $app = self::application($directory);
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        $input = new ArrayInput([]);
        $input->setInteractive(true);

        // The actual audited command uses hidden STDIN; no password option or stored initial credential.
        return $kernel->all()['vasey:create-admin']->run($input, new ConsoleOutput);
    }

    public static function serve(): void
    {
        self::require(($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1' && ($_SERVER['HTTP_HOST'] ?? null) === '127.0.0.1:8175');
        [$directory] = self::guard(['ready']);
        header('X-Vasey-Private-Content: '.getenv('VASEY_CONTENT_ID'));
        header('X-Robots-Tag: noindex, nofollow');
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
        self::require(! str_contains($path, "\0") && ! str_contains($path, '\\'));
        if (preg_match('~(?:^|/)\.|^/storage(?:/|$)|\.php(?:/|$)~i', $path)) {
            http_response_code(404);

            return;
        }
        $public = realpath(__DIR__.'/../../public');
        $privatePublic = $directory.'/public';
        $file = realpath($privatePublic.$path);
        if ($file === false) {
            $file = realpath($public.$path);
        }
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'txt' => 'text/plain'];
        if ($file !== false && is_file($file)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ((! str_starts_with($file, $public.'/') && ! str_starts_with($file, $privatePublic.'/'))
                || ! isset($types[$extension]) || ! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
                http_response_code(404);

                return;
            }
            header('Content-Type: '.$types[$extension]);
            header('X-Content-Type-Options: nosniff');
            header('Content-Length: '.filesize($file));
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                readfile($file);
            }

            return;
        }
        define('LARAVEL_START', microtime(true));
        self::application($directory)->handleRequest(Request::capture());
    }
}

umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI === 'cli' && count($argv) === 2) {
        match ($argv[1]) {
            'lease' => PersistentContentBootstrap::lease(),
            'initialize' => PersistentContentBootstrap::initialize(),
            'verify' => PersistentContentBootstrap::verify(),
            'operator' => exit(PersistentContentBootstrap::operator()),
            default => throw new RuntimeException('Unsupported invocation.'),
        };
    } elseif (PHP_SAPI === 'cli-server') {
        PersistentContentBootstrap::serve();
    } else {
        throw new RuntimeException('Unsupported invocation.');
    }
} catch (Throwable) {
    if (PHP_SAPI === 'cli-server') {
        http_response_code(503);
        echo 'Private content workspace is unavailable.';
    } else {
        fwrite(STDERR, "Persistent workspace isolation or operation failed. Retained files were not reset or removed.\n");
        exit(1);
    }
}
