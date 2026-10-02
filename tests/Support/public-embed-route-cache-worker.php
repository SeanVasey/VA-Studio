<?php

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

// Disposable CLI-only request evidence. This worker never uses the application's
// normal caches, storage, database or provider accounts, even in a MySQL CI job.
try {
    $directory = getenv('VASEY_EMBED_CACHE_DIRECTORY');
    if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || ! is_string($directory)
        || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || ! preg_match('/\Avasey-embed-cache-[a-f0-9]{24}\z/D', basename($directory))
        || getenv('LARAVEL_STORAGE_PATH') !== $directory
        || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
        || getenv('DB_URL') !== '' || getenv('CACHE_STORE') !== 'array'
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
        || getenv('APP_ROUTES_CACHE') !== $directory.'/routes.php'
        || getenv('APP_SERVICES_CACHE') !== $directory.'/services.php'
        || getenv('APP_PACKAGES_CACHE') !== $directory.'/packages.php'
        || getenv('APP_EVENTS_CACHE') !== $directory.'/events.php') {
        throw new RuntimeException('A disposable route-cache workspace is required.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';

    if (($argv[1] ?? null) === 'cache') {
        $console = $app->make(ConsoleKernel::class);
        $console->bootstrap();
        if (! $app->environment('testing') || storage_path() !== $directory
            || $console->call('route:cache', ['--quiet' => true]) !== 0) {
            throw new RuntimeException('Route cache compilation failed.');
        }
        echo json_encode(['cached' => is_file($directory.'/routes.php')], JSON_THROW_ON_ERROR);
        exit(0);
    }
    if (($argv[1] ?? null) !== 'requests') {
        throw new RuntimeException('Unknown worker operation.');
    }

    $kernel = $app->make(HttpKernel::class);
    $kernel->bootstrap();
    if (! $app->environment('testing') || storage_path() !== $directory) {
        throw new RuntimeException;
    }
    // Unknown slugs use the real controller/catalog with an empty routing fixture.
    // Publication and media evidence are established by PublicTrackEmbedTest.
    Schema::create('tracks', function (Blueprint $table): void {
        $table->id();
        $table->string('slug');
        $table->string('status');
    });

    $authGuardCalls = 0;
    Auth::partialMock()->shouldReceive('guard')->andReturnUsing(function () use (&$authGuardCalls): never {
        $authGuardCalls++;
        throw new RuntimeException('The public embed must not resolve an authentication guard.');
    });
    $result = ['cached' => $app->routesAreCached(), 'middleware' => [], 'responses' => [],
        'privacyFailures' => [], 'genericRateLimited' => 0, 'retryAfterCount' => 0];
    foreach (['embeds.show', 'embeds.preview'] as $name) {
        $result['middleware'][$name] = Route::getRoutes()->getByName($name)->gatherMiddleware();
    }
    $urls = array_fill(0, 121, '/embed/tracks/unknown');
    $urls = array_merge($urls, array_fill(0, 241, '/embed/tracks/unknown/preview/1'),
        ['/embed/tracks/unknown', '/embed/tracks/unknown/preview/1']);
    foreach ($urls as $index => $url) {
        $request = Request::create('https://audio.example.test'.$url, 'GET', [], ['vasey_session' => 'unused-cookie'], [],
            ['REMOTE_ADDR' => $index < 362 ? '198.51.100.17' : '198.51.100.18']);
        $response = $kernel->handle($request);
        $result['responses'][] = [$response->getStatusCode(), (int) $response->headers->get('X-RateLimit-Remaining'),
            (int) $response->headers->get('X-RateLimit-Limit')];
        $csp = "default-src 'none'; style-src 'self'; font-src 'self'; media-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors http: https:";
        if ($request->hasSession() || $response->headers->has('Set-Cookie') || $response->headers->has('X-Inertia')
            || $response->headers->has('X-Frame-Options')
            || $response->headers->get('Cache-Control') !== 'no-store, private'
            || $response->headers->get('Content-Security-Policy') !== $csp
            || $response->headers->get('X-Robots-Tag') !== 'noindex, nofollow'
            || $response->headers->get('Referrer-Policy') !== 'no-referrer'
            || $response->headers->get('X-Content-Type-Options') !== 'nosniff'
            || $response->headers->get('Permissions-Policy') !== 'autoplay=(), camera=(), microphone=(), geolocation=()'
            || in_array('Cookie', $response->getVary(), true)
            || $response->getContent() !== 'This preview is unavailable.') {
            $result['privacyFailures'][] = $index + 1;
        }
        if ($response->getStatusCode() === 429 && $response->getContent() === 'This preview is unavailable.') {
            $result['genericRateLimited']++;
            if ((int) $response->headers->get('Retry-After') > 0) {
                $result['retryAfterCount']++;
            }
        }
        $kernel->terminate($request, $response);
    }
    $result['authGuardCalls'] = $authGuardCalls;
    Mockery::close();
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Isolated embed route-cache verification failed: '.$exception::class."\n");
    exit(1);
}
