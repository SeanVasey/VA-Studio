<?php

use App\Domain\Catalog\Models\TrackMetadataPreset;
use App\Domain\Catalog\TrackMetadataPresets;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_PRESET_RACE_DIRECTORY');
$worker = getenv('VASEY_PRESET_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Metadata preset race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$isPresetLock = fn (string $query) => preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `track_metadata_presets`') && str_contains($query, 'for update');
DB::connection()->beforeExecuting(function ($query) use ($isPresetLock, $directory, $worker, $connection, $wait): void {
    if ($isPresetLock($query)) {
        file_put_contents($directory.'/ready-'.$worker, (string) $connection);
        $wait($directory.'/start-'.$worker);
    }
});
DB::listen(function ($query) use ($isPresetLock, $directory, $worker, $wait): void {
    if ($isPresetLock($query->sql)) {
        // Do not release the actual acquired record until the parent sees the independent waiter.
        touch($directory.'/locked-'.$worker);
        $wait($directory.'/commit');
    }
});
try {
    $actor = User::findOrFail($input['actor_id']);
    $command = app(TrackMetadataPresets::class);
    if ($input['operation'] === 'snapshot') {
        $result = ['result' => 'copied', 'snapshot' => $command->snapshot($input['preset_id'], $actor, $input['version'])];
    } elseif ($input['operation'] === 'archive') {
        $preset = $command->archive(TrackMetadataPreset::findOrFail($input['preset_id']), $input['version'], $actor);
        $result = ['result' => 'archived', 'version' => $preset->version];
    } else {
        $preset = $command->handle(TrackMetadataPreset::findOrFail($input['preset_id']), ['version' => $input['version'], 'mood' => $input['mood']], $actor);
        $result = ['result' => 'saved', 'version' => $preset->version];
    }
} catch (ValidationException $exception) {
    $result = ['result' => 'rejected', 'errors' => $exception->errors()];
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
