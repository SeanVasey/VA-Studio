<?php

use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Tester\CommandTester;

// This CLI-only fixture builder must never touch an existing installation or database.
$directory = getenv('VASEY_BROWSER_DIRECTORY');
$password = getenv('VASEY_BROWSER_PASSWORD');
if (PHP_SAPI !== 'cli' || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
    || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || ! preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory))
    || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_DATABASE') !== $directory.'/database.sqlite'
    || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || is_file($directory.'/config.php')
    || ! is_file($directory.'/database.sqlite') || is_link($directory.'/database.sqlite') || filesize($directory.'/database.sqlite') !== 0
    || ! is_string($password) || strlen($password) < 40 || getenv('APP_ENV') !== 'local' || getenv('DB_CONNECTION') !== 'sqlite'
    || getenv('APP_URL') !== 'http://127.0.0.1:8173') {
    throw new RuntimeException('Refusing browser fixtures outside a fresh isolated local run.');
}
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (! $app->environment('local') || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
    || storage_path() !== $directory || config('filesystems.disks.local.root') !== $directory.'/app/private') {
    throw new RuntimeException('Effective browser configuration does not match the isolated run.');
}
if (Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) !== 0) {
    throw new RuntimeException('Fresh browser database migration failed.');
}
$tester = new CommandTester($kernel->all()['vasey:create-admin']);
$tester->setInputs(['Synthetic Browser Operator', 'browser-operator@example.test', $password]);
if ($tester->execute([], ['interactive' => true]) !== 0) {
    throw new RuntimeException('Synthetic operator provisioning failed.');
}
$actor = User::where('email', 'browser-operator@example.test')->sole();
User::factory()->create(['name' => 'Synthetic Customer', 'email' => 'browser-customer@example.test', 'password' => $password]);
$fixtures = [];
foreach (['chromium-desktop', 'webkit-mobile'] as $project) {
    $editable = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic editable '.$project, 'slug' => 'editable-'.$project], $actor);
    $retained = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic retained URL '.$project, 'slug' => 'retained-'.$project], $actor);
    // A retained draft URL is synthetic history only; no readiness, rights or media are fabricated.
    DB::table('tracks')->where('id', $retained->id)->update(['published_slug' => $retained->slug]);
    $fixtures[$project] = ['editable' => ['title' => $editable->title, 'slug' => $editable->slug], 'retained' => ['title' => $retained->title, 'slug' => $retained->slug]];
}
file_put_contents($directory.'/fixtures.json', json_encode($fixtures, JSON_THROW_ON_ERROR));
chmod($directory.'/fixtures.json', 0600);
if (Artisan::call('vasey:doctor', ['--json' => true]) !== 0) {
    throw new RuntimeException('The isolated installation did not pass its required diagnostics.');
}
echo "Fresh SQLite migrations, interactive operator command and installation diagnostics passed.\n";
