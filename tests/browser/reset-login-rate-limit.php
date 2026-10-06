<?php

use Filament\Auth\Pages\Login;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\RateLimiter;

// Independent browser tests share one loopback server. Reset only their synthetic login counter.
// The application keeps its real limiter, including all submissions within each individual test.
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    if (PHP_SAPI !== 'cli' || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
        || getenv('APP_ENV') !== 'local' || getenv('APP_URL') !== 'http://127.0.0.1:8173'
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_CONNECTION') !== 'sqlite'
        || getenv('DB_DATABASE') !== $directory.'/database.sqlite' || getenv('DB_URL') !== ''
        || realpath($directory.'/database.sqlite') !== $directory.'/database.sqlite' || is_link($directory.'/database.sqlite')
        || ! is_file($directory.'/database.sqlite') || filesize($directory.'/database.sqlite') === 0
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
        || getenv('APP_ROUTES_CACHE') !== $directory.'/routes.php' || getenv('APP_EVENTS_CACHE') !== $directory.'/events.php'
        || getenv('CACHE_STORE') !== 'file' || realpath($directory.'/framework/cache/data') !== $directory.'/framework/cache/data'
        || realpath($directory.'/fixtures.json') !== $directory.'/fixtures.json' || is_link($directory.'/fixtures.json')
        || ! is_file($directory.'/fixtures.json')) {
        throw new RuntimeException('Not an isolated browser run.');
    }
    $fixtures = json_decode(file_get_contents($directory.'/fixtures.json'), true, 16, JSON_THROW_ON_ERROR);
    if (! is_array($fixtures) || array_keys($fixtures) !== ['chromium-desktop', 'webkit-mobile']) {
        throw new RuntimeException('Missing isolated fixture marker.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || config('app.url') !== 'http://127.0.0.1:8173'
        || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || storage_path() !== $directory || config('cache.default') !== 'file'
        || config('cache.limiter') !== null || config('cache.stores.file.driver') !== 'file'
        || config('cache.stores.file.path') !== $directory.'/framework/cache/data'
        || config('cache.stores.file.lock_path') !== $directory.'/framework/cache/data') {
        throw new RuntimeException('Effective browser paths do not match.');
    }
    // Exact key from danharrin/livewire-rate-limiting WithRateLimiting::getRateLimitKey().
    // Filament Login::authenticate() uses this component/method and the loopback server sees this IP.
    RateLimiter::clear('livewire-rate-limiter:'.sha1(Login::class.'|authenticate|127.0.0.1'));
    // The ordinary customer POST routes share this prefix and unauthenticated loopback signature.
    // Customer sessions use their own principal, not the staff web guard used by ThrottleRequests.
    RateLimiter::clear('customer-auth'.sha1('|127.0.0.1'));
} catch (Throwable) {
    fwrite(STDERR, "Refusing login-counter reset outside the isolated browser fixture.\n");
    exit(1);
}
