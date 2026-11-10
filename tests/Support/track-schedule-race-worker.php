<?php

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\TrackPublicationSchedules;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
    throw new LogicException('Track schedule race workers require testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
config($input['config']);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
Queue::fake();
// Every worker decides at the same instant, after the schedule came due.
Carbon::setTestNow($input['now']);
CarbonImmutable::setTestNow($input['now']);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$directory = getenv('VASEY_TRACK_SCHEDULE_RACE_DIRECTORY');
$worker = getenv('VASEY_TRACK_SCHEDULE_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Track schedule race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$paused = false;
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused): void {
    // Hold the schedule row's exclusive lock until the parent has seen the other worker queue behind this one.
    if (! $paused && ($input['pause_table'] ?? null) !== null && str_contains($query->sql, 'for update')
        && preg_match('/\Aselect\b.*\bfrom `'.preg_quote($input['pause_table'], '/').'`/i', $query->sql)) {
        $paused = true;
        touch($directory.'/locked-'.$worker);
        $wait($directory.'/commit');
    }
});
file_put_contents($directory.'/ready-'.$worker, (string) $connection);
$wait($directory.'/start-'.$worker);
try {
    $service = app(TrackPublicationSchedules::class);
    $result = match ($input['operation']) {
        'run' => (function () use ($service): array {
            $results = $service->runDue();

            return ['result' => $results[0]['outcome'] ?? 'nothing', 'count' => count($results)];
        })(),
        'cancel' => (function () use ($service, $input): array {
            $track = Track::findOrFail($input['track_id']);
            $service->cancel($track, User::findOrFail($input['actor_id']), $input['metadata_version'], $input['publication_version']);

            return ['result' => 'cancelled'];
        })(),
    };
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
} catch (ValidationException $error) {
    $result = ['result' => 'rejected', 'errors' => $error->errors()];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'transactionLevel' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
