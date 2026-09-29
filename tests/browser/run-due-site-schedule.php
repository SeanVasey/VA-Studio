<?php

use App\Domain\SiteBuilder\SiteContent;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

// Browser tests cannot wait for a real minute boundary. Inside the isolated fixture only, this process sets its own
// clock to the pending schedule's time and runs the production scheduler command once. The command and domain accept no
// time override; the served application and every other process keep the real clock.
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
} catch (Throwable) {
    fwrite(STDERR, "Refusing to run a scheduled publication outside the isolated browser fixture.\n");
    exit(1);
}
$pending = app(SiteContent::class)->pendingSchedule();
if ($pending === null) {
    fwrite(STDERR, "No scheduled publication is pending.\n");
    exit(1);
}
Carbon::setTestNow($pending->publish_at);
CarbonImmutable::setTestNow($pending->publish_at);
$code = Artisan::call('vasey:publish-scheduled-site-release');
fwrite($code === 0 ? STDOUT : STDERR, trim(Artisan::output()).PHP_EOL);
exit($code);
