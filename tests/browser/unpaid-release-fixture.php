<?php

use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\UnpaidRelease\UnpaidReleasePolicy;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Private disposable-harness capability; never loaded by public/index.php or an application provider. */
final class UnpaidReleaseBrowserFixture
{
    public const PROJECTS = ['chromium-desktop' => 'CHROMIUM', 'webkit-mobile' => 'WEBKIT'];

    public const COMPONENT = 'App\\Filament\\Resources\\TestUnpaidOrderResource\\Pages\\ListTestUnpaidOrders';

    public const ACCOUNT = 'acct_SYNTHETICONLY';

    public static function directory(): string
    {
        $directory = getenv('VASEY_BROWSER_DIRECTORY');
        self::check(is_string($directory) && ! is_link($directory) && realpath($directory) === $directory
            && realpath(dirname($directory)) === realpath(sys_get_temp_dir())
            && preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory)) === 1
            && (fileperms($directory) & 0777) === 0700 && function_exists('posix_geteuid')
            && fileowner($directory) === posix_geteuid());
        foreach (['APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://127.0.0.1:8173',
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/database.sqlite', 'DB_URL' => '', 'DB_FOREIGN_KEYS' => 'true',
            'LARAVEL_STORAGE_PATH' => $directory, 'APP_CONFIG_CACHE' => $directory.'/config.php',
            'APP_ROUTES_CACHE' => $directory.'/routes.php', 'APP_EVENTS_CACHE' => $directory.'/events.php',
            'SESSION_DRIVER' => 'file', 'SESSION_ENCRYPT' => 'true', 'CACHE_STORE' => 'file',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'FILESYSTEM_DISK' => 'local',
            'STRIPE_ACCOUNT_ID' => self::ACCOUNT, 'STRIPE_MODE' => 'test', 'STRIPE_TEST_SECRET_KEY' => '',
            'STRIPE_WEBHOOK_SECRET' => '', 'STRIPE_TEST_CHECKOUT_ENABLED' => 'false',
            'STRIPE_TEST_PAYMENT_PROCESSING_ENABLED' => 'false', 'STRIPE_TEST_FINALIZATION_ENABLED' => 'false',
            'STRIPE_WEBHOOK_ENABLED' => 'false'] as $key => $value) {
            self::check(getenv($key) === $value);
        }
        foreach (['config.php', 'routes.php', 'events.php'] as $cache) {
            self::check(! file_exists($directory.'/'.$cache) && ! is_link($directory.'/'.$cache));
        }
        self::check(in_array(getenv('VASEY_TEST_UNPAID_RELEASE_ENABLED'), [false, '', 'false'], true)
            && in_array(getenv('VASEY_TEST_UNPAID_RELEASE_POLICY'), [false, ''], true));
        foreach (['app', 'app/private', 'framework', 'framework/sessions', 'framework/cache', 'framework/cache/data'] as $child) {
            self::check(! is_link($directory.'/'.$child) && realpath($directory.'/'.$child) === $directory.'/'.$child);
        }
        self::privateFile($directory.'/database.sqlite', 33554432);
        $fixtures = self::json($directory.'/fixtures.json', 65536);
        self::check(array_keys($fixtures) === array_keys(self::PROJECTS));
        $marker = getenv('VASEY_BROWSER_EXCEPTION_MARKER');
        self::check(is_string($marker) && preg_match('/\A[a-f0-9]{64}\z/D', $marker) === 1);
        self::check(self::json($directory.'/exception-inspection-fixture-marker.json', 65536) === [
            'purpose' => 'retained-exception-native', 'marker' => $marker, 'database' => $directory.'/database.sqlite',
            'origin' => 'http://127.0.0.1:8173', 'baseOperatorId' => 1, 'account' => self::ACCOUNT,
        ]);

        return $directory;
    }

    public static function effective(string $directory): void
    {
        self::check(app()->environment('local') && config('app.debug') === false && config('app.url') === 'http://127.0.0.1:8173'
            && config('database.default') === 'sqlite' && config('database.connections.sqlite.database') === $directory.'/database.sqlite'
            && in_array(config('database.connections.sqlite.url'), [null, ''], true)
            && storage_path() === $directory && config('filesystems.disks.local.driver') === 'local'
            && config('filesystems.disks.local.root') === $directory.'/app/private'
            && config('session.driver') === 'file' && config('session.encrypt') === true
            && config('session.files') === $directory.'/framework/sessions'
            && config('cache.default') === 'file' && config('cache.stores.file.path') === $directory.'/framework/cache/data'
            && config('cache.stores.file.lock_path') === $directory.'/framework/cache/data'
            && config('queue.default') === 'sync' && config('mail.default') === 'array'
            && config('payments.stripe.account_id') === self::ACCOUNT && config('payments.stripe.mode') === 'test'
            && config('unpaid-release.enabled') === false && in_array(config('unpaid-release.policy'), [null, ''], true));
        foreach (['checkout_enabled', 'processing_enabled', 'finalization_enabled', 'webhook_enabled'] as $flag) {
            self::check(config('payments.stripe.'.$flag) === false);
        }
        foreach (['secret_key', 'webhook_secret'] as $secret) {
            self::check(in_array(config('payments.stripe.'.$secret), [null, ''], true));
        }
        self::check(DB::getDriverName() === 'sqlite'
            && (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys === 1);
        $databases = collect(DB::select('PRAGMA database_list'));
        self::check($databases->firstWhere('name', 'main')?->file === $directory.'/database.sqlite'
            && ! $databases->contains(fn ($db) => ! in_array($db->name, ['main', 'temp'], true)));
        $operator = User::findOrFail(1);
        self::check($operator->name === 'Synthetic Browser Operator' && $operator->email === 'browser-operator@example.test'
            && Gate::forUser($operator)->allows('administer-catalog')
            && AdminMultiFactor::satisfiedBy($operator));
    }

    public static function manifest(string $directory, string $project): array
    {
        self::check(array_key_exists($project, self::PROJECTS));
        $fixture = self::json($directory.'/unpaid-release-'.$project.'.json', 8388608);
        self::check(($fixture['purpose'] ?? null) === 'test-unpaid-browser-v1' && ($fixture['project'] ?? null) === $project
            && ($fixture['marker'] ?? null) === getenv('VASEY_BROWSER_EXCEPTION_MARKER')
            && ($fixture['database'] ?? null) === $directory.'/database.sqlite' && ($fixture['operatorId'] ?? null) === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', $fixture['capability'] ?? '') === 1
            && ($fixture['session']['id'] ?? null) === 'cs_test_BROWSERUNPAID'.self::PROJECTS[$project]
            && ($fixture['payment']['id'] ?? null) === 'pi_BROWSERUNPAID'.self::PROJECTS[$project]
            && ($fixture['session']['payment_intent'] ?? null) === $fixture['payment']['id']);
        self::privateFile($directory.'/unpaid-release-'.$project.'.jsonl', 16384, true);

        return $fixture;
    }

    /** Called only by tests/browser/server.php after its existing disposable-router guard. */
    public static function serve(): never
    {
        try {
            self::check(PHP_SAPI === 'cli-server' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
                && ($_SERVER['HTTP_HOST'] ?? '') === '127.0.0.1:8173' && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1'
                && ($_SERVER['QUERY_STRING'] ?? '') === ''
                && preg_match('~\A/livewire(?:-[A-Za-z0-9]+)?/update\z~D', parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '') === 1
                && str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json'));
            $header = $_SERVER['HTTP_X_VASEY_UNPAID_FIXTURE'] ?? '';
            self::check(preg_match('/\A(chromium-desktop|webkit-mobile):([a-f0-9]{64})\z/D', $header, $parts) === 1);
            $directory = self::directory();
            $fixture = self::manifest($directory, $parts[1]);
            self::check(hash_equals($fixture['capability'], $parts[2]));
            $input = fopen('php://input', 'rb');
            $body = is_resource($input) ? stream_get_contents($input, 131073) : false;
            if (is_resource($input)) {
                fclose($input);
            }
            self::check(is_string($body) && strlen($body) <= 131072);
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            self::check(is_array($payload['components'] ?? null) && count($payload['components']) === 1
                && is_string($payload['components'][0]['snapshot'] ?? null));
            $snapshot = json_decode($payload['components'][0]['snapshot'], true, 32, JSON_THROW_ON_ERROR);
            self::check(($snapshot['memo']['name'] ?? null) === self::COMPONENT);
            self::check(! file_exists(__DIR__.'/../../public/hot') && ! file_exists(__DIR__.'/../../storage/framework/maintenance.php'));
            define('LARAVEL_START', microtime(true));
            require_once __DIR__.'/../../vendor/autoload.php';
            $app = require __DIR__.'/../../bootstrap/app.php';
            $app->booted(function () use ($app, $directory, $fixture): void {
                self::effective($directory);
                config(['unpaid-release.enabled' => true,
                    'unpaid-release.policy' => json_encode(UnpaidReleasePolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
                $app->instance(StripePaymentGateway::class, self::gateway($directory, $fixture));
            });
            $app->handleRequest(Request::capture());
            exit;
        } catch (Throwable) {
            http_response_code(503);
            header('Cache-Control: no-store');
            echo 'Isolated unpaid-release fixture refused.';
            exit;
        }
    }

    private static function gateway(string $directory, array $fixture): StripePaymentGateway
    {
        return new class($directory, $fixture) implements StripePaymentGateway
        {
            public function __construct(private string $directory, private array $fixture) {}

            public function account(): array
            {
                $this->record('account');

                return ['object' => 'account', 'id' => UnpaidReleaseBrowserFixture::ACCOUNT];
            }

            public function retrieve(string $sessionId): array
            {
                UnpaidReleaseBrowserFixture::check($sessionId === $this->fixture['session']['id']);
                $this->record('retrieve');

                return $this->fixture['session'];
            }

            public function paymentIntent(string $paymentIntentId): array
            {
                UnpaidReleaseBrowserFixture::check($paymentIntentId === $this->fixture['payment']['id']);
                $this->record('payment_intent');

                return $this->fixture['payment'];
            }

            private function record(string $operation): void
            {
                $depth = DB::transactionLevel();
                UnpaidReleaseBrowserFixture::check($depth === 0);
                $path = $this->directory.'/unpaid-release-'.$this->fixture['project'].'.jsonl';
                UnpaidReleaseBrowserFixture::privateFile($path, 16384, true);
                $line = json_encode(['operation' => $operation, 'transactionLevel' => $depth], JSON_THROW_ON_ERROR)."\n";
                UnpaidReleaseBrowserFixture::check(file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === strlen($line));
            }
        };
    }

    public static function privateFile(string $path, int $maximum, bool $empty = false): void
    {
        clearstatcache(true, $path);
        self::check(is_file($path) && ! is_link($path) && realpath($path) === $path && is_readable($path)
            && filesize($path) >= ($empty ? 0 : 1) && filesize($path) <= $maximum
            && (fileperms($path) & 0777) === 0600 && fileowner($path) === posix_geteuid());
    }

    public static function json(string $path, int $maximum): array
    {
        self::privateFile($path, $maximum);
        $value = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        self::check(is_array($value));

        return $value;
    }

    public static function write(string $path, string $bytes): void
    {
        self::check(! file_exists($path) && ! is_link($path));
        $handle = fopen($path, 'x');
        self::check(is_resource($handle));
        try {
            self::check(chmod($path, 0600) && fwrite($handle, $bytes) === strlen($bytes) && fflush($handle));
        } finally {
            fclose($handle);
        }
    }

    public static function check(bool $condition): void
    {
        if (! $condition) {
            throw new RuntimeException('Isolated unpaid-release fixture refused.');
        }
    }
}
