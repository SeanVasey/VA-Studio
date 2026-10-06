<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BrowserLoginRateLimitFixtureTest extends TestCase
{
    private string $directory;

    private array $environment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/vasey-browser-'.bin2hex(random_bytes(8));
        mkdir($this->directory.'/framework/cache/data', 0700, true);
        mkdir($this->directory.'/framework/views', 0700, true);
        chmod($this->directory, 0700);
        (new \PDO('sqlite:'.$this->directory.'/database.sqlite'))->exec('VACUUM');
        file_put_contents($this->directory.'/fixtures.json', json_encode(['chromium-desktop' => [], 'webkit-mobile' => []], JSON_THROW_ON_ERROR));
        $this->environment = [
            'VASEY_BROWSER_DIRECTORY' => $this->directory, 'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_URL' => 'http://127.0.0.1:8173',
            'LARAVEL_STORAGE_PATH' => $this->directory, 'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $this->directory.'/database.sqlite', 'DB_URL' => '',
            'APP_CONFIG_CACHE' => $this->directory.'/config.php', 'APP_ROUTES_CACHE' => $this->directory.'/routes.php',
            'APP_EVENTS_CACHE' => $this->directory.'/events.php', 'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'file', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array',
            'LOG_CHANNEL' => 'stderr',
        ];
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_isolated_reset_clears_the_actual_customer_counter_and_keeps_other_counters_and_limits(): void
    {
        $this->assertSame(['blocked' => true, 'otherCounters' => [3, 4]], $this->probe('seed'));
        $reset = $this->reset();
        $this->assertSame(0, $reset->getExitCode(), $reset->getErrorOutput());
        // A new independent journey gets exactly the original ten attempts, then is blocked again.
        $this->assertSame(['accepted' => 10, 'blocked' => true, 'otherCounters' => [3, 4]], $this->probe('fresh'));
    }

    public function test_reset_refuses_a_different_environment_without_clearing_the_customer_counter(): void
    {
        $this->assertSame(['blocked' => true, 'otherCounters' => [3, 4]], $this->probe('seed'));
        $reset = $this->reset(['APP_ENV' => 'testing']);
        $this->assertSame(1, $reset->getExitCode());
        $this->assertStringContainsString('Refusing login-counter reset', $reset->getErrorOutput());
        $this->assertSame(['accepted' => 0, 'blocked' => true, 'otherCounters' => [3, 4]], $this->probe('fresh'));
    }

    private function reset(array $overrides = []): Process
    {
        $process = new Process([PHP_BINARY, 'tests/browser/reset-login-rate-limit.php'], dirname(__DIR__, 2), array_replace($this->environment, $overrides), null, 30);
        $process->run();

        return $process;
    }

    private function probe(string $mode): array
    {
        $source = <<<'PHP'
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$route = app('router')->getRoutes()->getByName('customer.sign-in.store');
if ($route === null || ! in_array('throttle:10,1,customer-auth', $route->gatherMiddleware(), true)) {
    throw new RuntimeException('The actual customer limiter contract changed.');
}
$request = Illuminate\Http\Request::create('http://127.0.0.1:8173/account/sign-in', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);
$request->setRouteResolver(fn () => $route);
$request->setUserResolver(fn () => null);
$limiter = app(Illuminate\Cache\RateLimiter::class);
$middleware = new Illuminate\Routing\Middleware\ThrottleRequests($limiter);
$other = ['customer-pages'.sha1('|127.0.0.1'), 'customer-auth'.sha1('|127.0.0.2')];
if ($argv[1] === 'seed') {
    foreach ($other as $index => $key) {
        for ($i = 0; $i < $index + 3; $i++) { $limiter->hit($key, 60); }
    }
}
$accepted = 0;
$blocked = false;
for ($i = 0; $i < 11; $i++) {
    try {
        $middleware->handle($request, fn () => new Symfony\Component\HttpFoundation\Response('', 204), '10', '1', 'customer-auth');
        $accepted++;
    } catch (Illuminate\Http\Exceptions\ThrottleRequestsException) {
        $blocked = true;
        break;
    }
}
$result = ['blocked' => $blocked, 'otherCounters' => array_map(fn ($key) => $limiter->attempts($key), $other)];
if ($argv[1] === 'fresh') { $result = ['accepted' => $accepted, ...$result]; }
echo json_encode($result, JSON_THROW_ON_ERROR);
PHP;
        $process = new Process([PHP_BINARY, '-r', $source, $mode], dirname(__DIR__, 2), $this->environment, null, 30);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());

        return json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
    }
}
