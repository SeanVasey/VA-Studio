<?php

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_TRACK_RACE_DIRECTORY');
$worker = getenv('VASEY_TRACK_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Track race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$isTrackLock = fn (string $query) => preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `tracks`') && str_contains($query, 'for update');
DB::connection()->beforeExecuting(function ($query) use ($isTrackLock, $directory, $worker, $connection, $wait) {
    if ($isTrackLock($query)) {
        file_put_contents($directory.'/ready-'.$worker, (string) $connection);
        $wait($directory.'/start');
    }
});
DB::listen(function ($query) use ($isTrackLock, $directory, $worker, $wait) {
    if ($isTrackLock($query->sql)) {
        // Keep the first acquired lock until the parent observes the other worker blocked on it.
        touch($directory.'/locked-'.$worker);
        $wait($directory.'/commit');
    }
});
try {
    $track = app(SaveTrackMetadata::class)->handle(Track::findOrFail($input['track_id']), [
        'title' => 'Synthetic edit '.$worker, 'metadata_version' => $input['metadata_version'],
    ], User::findOrFail($input['actor_id']));
    $result = ['result' => 'saved', 'version' => $track->metadata_version, 'title' => $track->title];
} catch (ValidationException $exception) {
    $result = ['result' => 'rejected', 'errors' => $exception->errors()];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
