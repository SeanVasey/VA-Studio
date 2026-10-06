<?php

// This entry is used only by private-alpha.mjs. It is never an application route.
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Tester\CommandTester;

final class PrivateAlphaBootstrap
{
    private const ORIGIN = 'http://127.0.0.1:8174';

    private static function require(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Private alpha isolation check failed.');
        }
    }

    public static function guard(bool $prepare): string
    {
        $directory = getenv('VASEY_ALPHA_DIRECTORY');
        self::require(is_string($directory) && ! is_link($directory) && realpath($directory) === $directory
            && realpath(dirname($directory)) === realpath(sys_get_temp_dir())
            && preg_match('/\Avasey-alpha-[A-Za-z0-9]+\z/D', basename($directory)) === 1
            && (fileperms($directory) & 0777) === 0700);
        if (function_exists('posix_geteuid')) {
            self::require(fileowner($directory) === posix_geteuid());
        }
        foreach (['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => self::ORIGIN,
            'LARAVEL_STORAGE_PATH' => $directory, 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite',
            'DB_URL' => '', 'SESSION_DRIVER' => 'file', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'database',
            'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local'] as $key => $value) {
            self::require(getenv($key) === $value);
        }
        foreach (['CONFIG' => 'config', 'ROUTES' => 'routes', 'EVENTS' => 'events', 'PACKAGES' => 'packages', 'SERVICES' => 'services'] as $key => $file) {
            self::require(getenv('APP_'.$key.'_CACHE') === $directory.'/'.$file.'.php' && ! is_link($directory.'/'.$file.'.php'));
            if (in_array($key, ['CONFIG', 'ROUTES', 'EVENTS'], true)) {
                self::require(! file_exists($directory.'/'.$file.'.php'));
            }
        }
        foreach (['STRIPE_WEBHOOK_ENABLED', 'STRIPE_TEST_CHECKOUT_ENABLED', 'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED',
            'STRIPE_TEST_FINALIZATION_ENABLED', 'VASEY_TEST_CONTRACT_ISSUANCE_ENABLED', 'VASEY_TEST_FULFILLMENT_ACTIVATION_ENABLED',
            'VASEY_TEST_DELIVERY_ACCESS_ENABLED', 'CONTACT_INQUIRIES_ENABLED'] as $key) {
            self::require(getenv($key) === 'false');
        }
        foreach (['STRIPE_ACCOUNT_ID', 'STRIPE_TEST_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'MEDIA_TAG_PATH', 'MEDIA_TAG_SHA256'] as $key) {
            self::require(getenv($key) === '');
        }
        foreach (['app', 'app/private', 'framework', 'framework/views', 'framework/sessions', 'framework/cache', 'framework/cache/data', 'logs', 'public'] as $child) {
            self::require(is_dir($directory.'/'.$child) && ! is_link($directory.'/'.$child) && realpath($directory.'/'.$child) === $directory.'/'.$child);
        }
        foreach (['database.sqlite', 'identity.json'] as $file) {
            self::require(is_file($directory.'/'.$file) && ! is_link($directory.'/'.$file)
                && (fileperms($directory.'/'.$file) & 0077) === 0);
        }
        self::require(glob($directory.'/.env*') === []);
        $identity = json_decode(file_get_contents($directory.'/identity.json'), true, flags: JSON_THROW_ON_ERROR);
        $marker = getenv('VASEY_ALPHA_MARKER');
        self::require(is_string($marker) && preg_match('/\A[a-f0-9]{64}\z/D', $marker) === 1
            && $identity === ['marker' => $marker, 'origin' => self::ORIGIN, 'state' => $prepare ? 'fresh' : 'ready']);
        if ($prepare) {
            self::require(filesize($directory.'/database.sqlite') === 0 && strlen((string) getenv('VASEY_ALPHA_PASSWORD')) >= 40);
        }

        return $directory;
    }

    private static function application(string $directory): \Illuminate\Foundation\Application
    {
        require_once __DIR__.'/../../vendor/autoload.php';
        $app = require __DIR__.'/../../bootstrap/app.php';
        // Never load the checkout's .env, even for optional policies absent from the launcher.
        $app->useEnvironmentPath($directory);
        $app->useStoragePath($directory);
        $app->usePublicPath($directory.'/public');
        $app->afterBootstrapping(BootProviders::class, function ($app) use ($directory): void {
            self::require($app->environment('local') && config('app.debug') === false && config('app.url') === self::ORIGIN
                && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $directory.'/database.sqlite'
                && ! config('database.connections.sqlite.url') && storage_path() === $directory && public_path() === $directory.'/public'
                && config('filesystems.default') === 'local' && config('filesystems.disks.local.root') === $directory.'/app/private'
                && config('mail.default') === 'array' && config('queue.default') === 'database'
                && config('cache.default') === 'file' && config('session.driver') === 'file'
                && config('session.files') === $directory.'/framework/sessions' && config('view.compiled') === $directory.'/framework/views');
            foreach (['payments.stripe.webhook_enabled', 'payments.stripe.checkout_enabled', 'payments.stripe.processing_enabled',
                'payments.stripe.finalization_enabled', 'contracts.test_issuance_enabled', 'delivery.test_activation_enabled',
                'delivery.test_access_enabled', 'inquiries.enabled'] as $key) {
                self::require(config($key) === false);
            }
            foreach (['commerce.test_pricing_policy', 'commerce.test_promotions', 'commerce.test_inventory_policy',
                'commerce.test_exclusive_selection_policy', 'commerce.test_order_policy', 'commerce.test_checkout_policy',
                'payments.stripe.finalization_policy', 'contracts.test_issuance_policy', 'delivery.test_activation_policy',
                'delivery.test_access_policy', 'payments.stripe.secret_key', 'payments.stripe.webhook_secret', 'payments.stripe.account_id'] as $key) {
                self::require(in_array(config($key), [null, ''], true));
            }
            foreach (['ffmpeg', 'ffprobe', 'prlimit', 'clamscan'] as $tool) {
                self::require(config('media.'.$tool) === $directory.'/disabled-'.$tool && ! file_exists($directory.'/disabled-'.$tool));
            }
        });

        return $app;
    }

    public static function prepare(): void
    {
        $directory = self::guard(true);
        $app = self::application($directory);
        $kernel = $app->make(Kernel::class);
        $kernel->bootstrap();
        // Composer --no-scripts needs these assets; publish only inside this run.
        self::require(Artisan::call('filament:assets', ['--no-interaction' => true]) === 0);
        $sourcePublic = realpath(__DIR__.'/../../public');
        $build = realpath($sourcePublic.'/build');
        if ($build !== false) {
            self::require(is_dir($build) && str_starts_with($build, $sourcePublic.'/')
                && symlink($build, $directory.'/public/build'));
        }
        self::require(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0);
        $tester = new CommandTester($kernel->all()['vasey:create-admin']);
        $tester->setInputs(['Synthetic Alpha Operator', 'synthetic-alpha-operator@example.test', getenv('VASEY_ALPHA_PASSWORD')]);
        self::require($tester->execute([], ['interactive' => true]) === 0);
        $actor = User::where('email', 'synthetic-alpha-operator@example.test')->sole();
        foreach (['metadata-practice' => 'SYNTHETIC ALPHA — Metadata practice', 'publication-blockers' => 'SYNTHETIC ALPHA — Publication blockers'] as $slug => $title) {
            app(SaveTrackMetadata::class)->handle(null, ['title' => $title, 'slug' => 'synthetic-alpha-'.$slug,
                'artist' => 'Synthetic fixture — no real recording', 'description' => 'Disposable operator practice only. No audio, artwork, rights clearance or license is supplied.'], $actor);
        }
        self::require(file_put_contents($directory.'/identity.json', json_encode(['marker' => getenv('VASEY_ALPHA_MARKER'),
            'origin' => self::ORIGIN, 'state' => 'ready'], JSON_THROW_ON_ERROR), LOCK_EX) !== false);
        echo "Private alpha fixtures prepared.\n";
    }

    public static function serve(): void
    {
        self::require(($_SERVER['REMOTE_ADDR'] ?? null) === '127.0.0.1' && ($_SERVER['HTTP_HOST'] ?? null) === '127.0.0.1:8174');
        $directory = self::guard(false);
        header('X-Vasey-Private-Alpha: '.getenv('VASEY_ALPHA_MARKER'));
        header('X-Robots-Tag: noindex, nofollow');
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
        self::require(! str_contains($path, "\0") && ! str_contains($path, '\\'));
        if (preg_match('~(?:^|/)\.|^/storage(?:/|$)|\.php(?:/|$)~i', $path)) {
            http_response_code(404);
            return;
        }
        $public = realpath(__DIR__.'/../../public');
        $alphaPublic = realpath($directory.'/public');
        $file = realpath($alphaPublic.$path);
        if ($file === false) {
            $file = realpath($public.$path);
        }
        $types = ['css' => 'text/css', 'js' => 'text/javascript', 'json' => 'application/json', 'svg' => 'image/svg+xml',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'ico' => 'image/x-icon',
            'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'txt' => 'text/plain'];
        if ($file !== false && is_file($file)) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ((! str_starts_with($file, $public.'/') && ! str_starts_with($file, $alphaPublic.'/'))
                || ! isset($types[$extension]) || ! in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
                http_response_code(404);
                return;
            }
            // Never return false: the built-in server must not execute checkout PHP.
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

try {
    if (PHP_SAPI === 'cli' && array_slice($argv, 1) === ['prepare']) {
        PrivateAlphaBootstrap::prepare();
    } elseif (PHP_SAPI === 'cli-server') {
        PrivateAlphaBootstrap::serve();
    } else {
        throw new RuntimeException('Unsupported private alpha invocation.');
    }
} catch (Throwable) {
    if (PHP_SAPI === 'cli-server') {
        http_response_code(503);
        echo 'Private alpha is unavailable.';
    } else {
        fwrite(STDERR, "Private alpha isolation or preparation failed.\n");
        exit(1);
    }
}
