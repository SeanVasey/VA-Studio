<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Vite;

// Render the real Blade template for native UI transport only. No catalog readiness is fabricated.
$hotFile = null;
$ownsHotFile = false;
$exitCode = 0;
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
    $mode = $argv[1] ?? '';
    if (count($argv) > 2 || ! in_array($mode, ['', 'hot-server', 'foreign-assets', 'hot-server-and-foreign-assets'], true)) {
        throw new RuntimeException('Unknown isolated font configuration.');
    }
    if ($mode !== '') {
        $hot = $mode !== 'foreign-assets';
        $foreign = $mode !== 'hot-server';
        $hotFile = $directory.'/embed-hot-'.bin2hex(random_bytes(12));
        if ($hot) {
            $handle = fopen($hotFile, 'xb');
            if ($handle === false) { throw new RuntimeException; }
            $ownsHotFile = true;
            try {
                if (fwrite($handle, 'http://localhost:5173') !== strlen('http://localhost:5173')) { throw new RuntimeException; }
            } finally { fclose($handle); }
        }
        Vite::useHotFile($hotFile);
        $assetOrigin = $foreign ? 'https://assets.example.test' : '';
        config(['app.asset_url' => $assetOrigin]);
        app('url')->useAssetOrigin($assetOrigin);
        fwrite(STDERR, json_encode(['mode' => $mode, 'hot' => Vite::isRunningHot(),
            'assetProbe' => asset('css/track-embed-fonts.css')], JSON_THROW_ON_ERROR)."\n");
    }
    echo view('public-track-embed', [
        'title' => 'Synthetic browser preview', 'artist' => 'Nonbinding browser fixture',
        'storeUrl' => 'http://127.0.0.1:8173/tracks/synthetic-browser-track',
        'previewUrl' => '/embed/tracks/synthetic-browser-track/preview/7001',
    ])->render();
} catch (Throwable) {
    fwrite(STDERR, "Isolated embed rendering failed.\n");
    $exitCode = 1;
} finally {
    if ($ownsHotFile && is_string($hotFile) && is_file($hotFile)) { unlink($hotFile); }
}
if ($exitCode !== 0) { exit($exitCode); }
