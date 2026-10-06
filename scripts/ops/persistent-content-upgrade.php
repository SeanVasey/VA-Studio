<?php

// Explicit local SQLite copy upgrade only. Never an HTTP/application route.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Support\Facades\Artisan;

final class PrivateCopyUpgrade
{
    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Private copy upgrade check failed.');
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

    private static function ancestors(string $directory): void
    {
        self::require(function_exists('posix_geteuid') && self::canonical($directory) && fileowner($directory) === posix_geteuid()
            && (fileperms($directory) & 07777) === 0700);
        $parent = dirname($directory);
        self::require(self::canonical($parent) && fileowner($parent) === posix_geteuid() && (fileperms($parent) & 0022) === 0);
        $cursor = '/';
        foreach (['', ...explode('/', substr($parent, 1))] as $part) {
            if ($part !== '') {
                $cursor = rtrim($cursor, '/').'/'.$part;
            }
            self::require(is_dir($cursor) && ((fileperms($cursor) & 0022) === 0
                || (fileowner($cursor) === 0 && (fileperms($cursor) & 01000) !== 0)));
        }
    }

    private static function file(string $path, int $maximum = 16777216): void
    {
        self::require(self::canonical($path) && is_file($path) && fileowner($path) === posix_geteuid());
        $stat = lstat($path);
        self::require(($stat['mode'] & 07777) === 0600 && $stat['nlink'] === 1 && $stat['size'] <= $maximum);
    }

    public static function sourceLease(): void
    {
        $directory = getenv('VASEY_CONTENT_DIRECTORY');
        self::require(is_string($directory));
        self::ancestors($directory);
        self::file($directory.'/identity.json', 16384);
        self::file($directory.'/lease', 16384);
        self::require(hash_file('sha256', $directory.'/identity.json') === getenv('VASEY_UPGRADE_SOURCE_IDENTITY_SHA256'));
        $identity = json_decode(file_get_contents($directory.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
        $stat = lstat($directory);
        self::require($identity['state'] === 'ready' && $identity['directory'] === $directory
            && $identity['directory_identity'] === ['dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino']]
            && $identity['app_key'] === getenv('APP_KEY') && $identity['checkout'] === getenv('VASEY_CONTENT_CHECKOUT'));
        $operation = getenv('VASEY_UPGRADE_OPERATION');
        self::require(is_string($operation) && preg_match('/\A[a-f0-9]{64}\z/D', $operation) === 1);
        // Read-only descriptor: even the source lease token is preserved, including after a crash.
        $handle = fopen($directory.'/lease', 'rb');
        self::require(flock($handle, LOCK_EX | LOCK_NB));
        try {
            echo 'SOURCE-LEASED '.$operation."\n";
            fflush(STDOUT);
            while (! feof(STDIN)) {
                fread(STDIN, 1024);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private static function target(): array
    {
        clearstatcache();
        $directory = getenv('VASEY_CONTENT_DIRECTORY');
        self::require(is_string($directory));
        self::ancestors($directory);
        foreach (['identity.json', 'lease', 'upgrade-plan.json'] as $file) {
            self::file($directory.'/'.$file);
        }
        self::file($directory.'/database.sqlite', PHP_INT_MAX);
        $plan = json_decode(file_get_contents($directory.'/upgrade-plan.json'), true, flags: JSON_THROW_ON_ERROR);
        self::require(hash_file('sha256', $directory.'/upgrade-plan.json') === getenv('VASEY_UPGRADE_PLAN_SHA256')
            && $plan['schema_version'] === 1 && $plan['operation_id'] === getenv('VASEY_UPGRADE_OPERATION')
            && hash_file('sha256', $directory.'/identity.json') === $plan['target_initial_identity_sha256']);
        $identity = json_decode(file_get_contents($directory.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
        $stat = lstat($directory);
        self::require($identity['state'] === 'initializing' && $identity['directory'] === $directory
            && $identity['directory_identity'] === ['dev' => (string) $stat['dev'], 'ino' => (string) $stat['ino']]
            && $identity['checkout'] === realpath(__DIR__.'/../..') && $identity['checkout'] === getenv('VASEY_CONTENT_CHECKOUT')
            && $identity['schema_hash'] === $plan['target_schema_hash'] && $identity['app_key'] === getenv('APP_KEY')
            && $identity['session_cookie'] === getenv('SESSION_COOKIE') && getenv('APP_ENV') === 'local'
            && getenv('APP_DEBUG') === 'false' && getenv('APP_URL') === 'http://127.0.0.1:8175'
            && getenv('DB_CONNECTION') === 'sqlite' && getenv('DB_DATABASE') === $directory.'/database.sqlite'
            && getenv('DB_URL') === '' && getenv('LARAVEL_STORAGE_PATH') === $directory);
        self::require(glob($directory.'/.env*') === []);
        foreach (['config', 'routes', 'events'] as $cache) {
            self::require(! file_exists($directory.'/'.$cache.'.php') && ! is_link($directory.'/'.$cache.'.php'));
        }
        foreach (['CONFIG' => 'config', 'ROUTES' => 'routes', 'EVENTS' => 'events', 'PACKAGES' => 'packages', 'SERVICES' => 'services'] as $key => $file) {
            self::require(getenv('APP_'.$key.'_CACHE') === $directory.'/'.$file.'.php');
        }
        $token = getenv('VASEY_CONTENT_LEASE');
        self::require(is_string($token) && preg_match('/\A[a-f0-9]{64}\z/D', $token) === 1
            && hash_equals($token, file_get_contents($directory.'/lease')));
        $handle = fopen($directory.'/lease', 'r+');
        try {
            $unlocked = flock($handle, LOCK_EX | LOCK_NB);
            if ($unlocked) {
                flock($handle, LOCK_UN);
            }
            self::require(! $unlocked);
        } finally {
            fclose($handle);
        }

        return [$directory, $identity, $plan];
    }

    private static function database(string $directory): PDO
    {
        $database = new PDO('sqlite:'.$directory.'/database.sqlite', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database->exec('PRAGMA busy_timeout = 5000');
        $database->exec('PRAGMA foreign_keys = ON');
        $database->exec('PRAGMA query_only = ON');
        self::require($database->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN) === ['ok']
            && $database->query('PRAGMA foreign_key_check')->fetchAll() === []);

        return $database;
    }

    private static function identifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private static function rows(PDO $database, string $table, array $columns, ?int $maximumMigrationId = null): array
    {
        $fields = implode(',', array_map(self::identifier(...), $columns));
        $where = $maximumMigrationId === null ? '' : ' WHERE id <= '.$maximumMigrationId;
        $statement = $database->query('SELECT '.$fields.' FROM '.self::identifier($table).$where.' ORDER BY '.$fields);
        $digest = hash_init('sha256');
        $count = 0;
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            // PHP serialization retains scalar types and arbitrary BLOB bytes without publishing them.
            $encoded = serialize($row);
            hash_update($digest, strlen($encoded).':'.$encoded);
            $count++;
        }

        return ['count' => $count, 'sha256' => hash_final($digest)];
    }

    private static function columns(PDO $database, string $table): array
    {
        return $database->query('PRAGMA table_info('.self::identifier($table).')')->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function snapshot(): void
    {
        [$directory, , $plan] = self::target();
        $database = self::database($directory);
        $migrations = $database->query('SELECT id,migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::require(array_column($migrations, 'migration') === array_column($plan['applied_migrations'], 'name'));
        $maximum = max(array_column($migrations, 'id'));
        $tables = [];
        foreach ($database->query("SELECT name FROM sqlite_schema WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $columns = self::columns($database, $name);
            $tables[] = ['name' => $name, 'columns' => $columns, 'rows' => self::rows($database, $name, array_column($columns, 'name'), $name === 'migrations' ? $maximum : null)];
        }
        $baseline = [
            'schema_version' => 1, 'operation_id' => $plan['operation_id'], 'tables' => $tables,
            'migrations' => $migrations, 'maximum_migration_id' => $maximum,
            'sequences' => $database->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll(PDO::FETCH_ASSOC),
            'guards' => $database->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE type IN ('trigger','index') ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC),
        ];
        self::require(! file_exists($directory.'/upgrade-baseline.json'));
        self::require(file_put_contents($directory.'/upgrade-baseline.json', json_encode($baseline, JSON_THROW_ON_ERROR), LOCK_EX) !== false);
    }

    private static function application(string $directory): Application
    {
        $loader = require __DIR__.'/../../vendor/autoload.php';
        $checkout = realpath(__DIR__.'/../..');
        self::require(realpath($loader->getPrefixesPsr4()['App\\'][0]) === $checkout.'/app');
        foreach ($loader->getClassMap() as $class => $file) {
            if (str_starts_with($class, 'App\\')) {
                self::require(str_starts_with((string) realpath($file), $checkout.'/app/'));
            }
        }
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->useEnvironmentPath($directory);
        $app->useStoragePath($directory);
        $app->usePublicPath($directory.'/public');
        $app->afterBootstrapping(BootProviders::class, static function ($app) use ($directory): void {
            self::require($app->environment('local') && config('app.debug') === false && config('app.key') === getenv('APP_KEY')
                && config('app.url') === 'http://127.0.0.1:8175' && config('app.previous_keys') === []
                && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $directory.'/database.sqlite'
                && ! config('database.connections.sqlite.url') && storage_path() === $directory && public_path() === $directory.'/public'
                && config('filesystems.disks.local.root') === $directory.'/app/private' && config('filesystems.disks.local.serve') === false
                && config('filesystems.default') === 'local' && config('mail.default') === 'array' && config('queue.default') === 'database'
                && config('cache.default') === 'file' && config('session.driver') === 'file' && config('session.encrypt') === true
                && config('session.cookie') === getenv('SESSION_COOKIE') && config('session.domain') === null
                && config('session.http_only') === true && config('session.secure') === false && config('session.same_site') === 'strict'
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
                self::require(config('media.'.$tool) === $directory.'/disabled-'.$tool && ! file_exists($directory.'/disabled-'.$tool));
            }
        });

        return $app;
    }

    private static function compare(PDO $database, array $baseline, array $plan): void
    {
        foreach ($baseline['tables'] as $table) {
            $columns = self::columns($database, $table['name']);
            self::require(array_slice($columns, 0, count($table['columns'])) === $table['columns']);
            self::require(self::rows($database, $table['name'], array_column($table['columns'], 'name'), $table['name'] === 'migrations' ? $baseline['maximum_migration_id'] : null) === $table['rows']);
        }
        $migrations = $database->query('SELECT id,migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        self::require(array_slice($migrations, 0, count($baseline['migrations'])) === $baseline['migrations']
            && array_column($migrations, 'migration') === [...array_column($plan['applied_migrations'], 'name'), ...array_column($plan['pending_migrations'], 'name')]);
        $new = array_slice($migrations, count($baseline['migrations']));
        $batch = max(array_column($baseline['migrations'], 'batch')) + 1;
        self::require(array_reduce($new, static fn (bool $ok, array $migration): bool => $ok && $migration['batch'] === $batch, true));
        $sequences = $database->query('SELECT name,seq FROM sqlite_sequence ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($baseline['sequences'] as $sequence) {
            $expected = $sequence;
            if ($sequence['name'] === 'migrations') {
                $expected['seq'] += count($plan['pending_migrations']);
            }
            self::require(in_array($expected, $sequences, true));
        }
        $guards = $database->query("SELECT type,name,tbl_name,sql FROM sqlite_schema WHERE type IN ('trigger','index') ORDER BY type,name")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($baseline['guards'] as $guard) {
            self::require(in_array($guard, $guards, true));
        }
    }

    private static function copyBuild(string $source, string $target): void
    {
        self::require(self::canonical($source) && is_dir($source) && ! file_exists($target) && mkdir($target, 0700));
        foreach (scandir($source) as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $file = $source.'/'.$name;
            self::require(self::canonical($file));
            if (is_dir($file)) {
                self::copyBuild($file, $target.'/'.$name);
            } else {
                self::require(is_file($file) && copy($file, $target.'/'.$name) && chmod($target.'/'.$name, 0600));
            }
        }
    }

    private static function secureGeneratedFiles(string $directory): void
    {
        foreach (scandir($directory) as $name) {
            if (in_array($name, ['.', '..'], true)) {
                continue;
            }
            $path = $directory.'/'.$name;
            self::require(self::canonical($path) && fileowner($path) === posix_geteuid());
            if (is_dir($path)) {
                self::require(chmod($path, 0700));
                self::secureGeneratedFiles($path);
            } else {
                self::require(is_file($path) && lstat($path)['nlink'] === 1 && chmod($path, 0600));
            }
        }
    }

    public static function apply(): void
    {
        [$directory, , $plan] = self::target();
        self::file($directory.'/upgrade-baseline.json');
        $baseline = json_decode(file_get_contents($directory.'/upgrade-baseline.json'), true, flags: JSON_THROW_ON_ERROR);
        self::require($baseline['operation_id'] === $plan['operation_id'] && ! file_exists($directory.'/upgrade-result.json'));
        $app = self::application($directory);
        $app->make(Kernel::class)->bootstrap();
        self::require(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0);
        $app->make('db')->disconnect();
        self::compare(self::database($directory), $baseline, $plan);
        self::require(Artisan::call('filament:assets', ['--no-interaction' => true]) === 0);
        self::copyBuild(realpath(__DIR__.'/../..').'/public/build', $directory.'/public/build');
        self::secureGeneratedFiles($directory);
        self::target();
        self::require(file_put_contents($directory.'/upgrade-result.json', json_encode([
            'schema_version' => 1, 'operation_id' => $plan['operation_id'], 'old_tables_verified' => count($baseline['tables']),
            'old_rows_verified' => array_sum(array_column(array_column($baseline['tables'], 'rows'), 'count')),
            'old_sequences_verified' => count($baseline['sequences']), 'old_guards_verified' => count($baseline['guards']),
            'migration_sequence_advance' => count($plan['pending_migrations']), 'result' => 'COPY_VERIFIED',
        ], JSON_THROW_ON_ERROR), LOCK_EX) !== false);
    }
}

umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
        throw new RuntimeException('Unsupported invocation.');
    }
    match ($argv[1]) {
        'source-lease' => PrivateCopyUpgrade::sourceLease(),
        'snapshot' => PrivateCopyUpgrade::snapshot(),
        'apply' => PrivateCopyUpgrade::apply(),
        default => throw new RuntimeException('Unsupported invocation.'),
    };
} catch (Throwable) {
    fwrite(STDERR, "Private copy upgrade checks failed. Original workspace was not changed; incomplete destination remains unavailable.\n");
    exit(1);
}
