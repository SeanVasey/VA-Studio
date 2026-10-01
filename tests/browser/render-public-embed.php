<?php

use Illuminate\Contracts\Console\Kernel;

// Render the real Blade template for native UI transport only. No catalog readiness is fabricated.
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'local' || ! is_string($directory)
        || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
        || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
        || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
        || getenv('DB_CONNECTION') !== 'sqlite' || getenv('APP_URL') !== 'http://127.0.0.1:8173') {
        throw new RuntimeException('An isolated browser run is required.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('local') || storage_path() !== $directory) { throw new RuntimeException; }
    echo view('public-track-embed', [
        'title' => 'Synthetic browser preview', 'artist' => 'Nonbinding browser fixture',
        'storeUrl' => 'http://127.0.0.1:8173/tracks/synthetic-browser-track',
        'previewUrl' => '/embed/tracks/synthetic-browser-track/preview/7001',
    ])->render();
} catch (Throwable) {
    fwrite(STDERR, "Isolated embed rendering failed.\n");
    exit(1);
}
