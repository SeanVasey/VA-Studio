<?php

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
    throw new LogicException('Track publication race workers require testing MySQL.');
}
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = getenv('VASEY_TRACK_PUBLICATION_RACE_DIRECTORY');
$worker = getenv('VASEY_TRACK_PUBLICATION_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Track publication race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$isLock = fn (string $query, string $table) => preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `'.$table.'`') && str_contains($query, 'for update');
$barrierTable = $input['mode'] === 'authority' ? 'users' : 'tracks';
if ($input['mode'] === 'overlap') {
    $ready = false;
    DB::connection()->beforeExecuting(function ($query) use ($isLock, $directory, $worker, $connection, $wait, &$ready): void {
        if (! $ready && $isLock($query, 'tracks')) {
            $ready = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start');
        }
    });
} else {
    file_put_contents($directory.'/ready-'.$worker, (string) $connection);
    $wait($directory.'/start-'.$worker);
}
$locked = false;
DB::listen(function ($query) use ($isLock, $barrierTable, $directory, $worker, $wait, &$locked): void {
    if (! $locked && $isLock($query->sql, $barrierTable)) {
        $locked = true;
        // The parent must observe an actual InnoDB wait on this exact acquired row before release.
        touch($directory.'/locked-'.$worker);
        $wait($directory.'/commit');
    }
});
try {
    if ($input['operation'] === 'withdraw') {
        DB::transaction(function () use ($input): void {
            $actor = User::query()->lockForUpdate()->findOrFail($input['actor_id']);
            $actor->is_admin = false;
            $actor->save();
        });
        $result = ['result' => 'withdrawn'];
    } elseif ($input['operation'] === 'edit') {
        $track = app(SaveTrackMetadata::class)->handle(Track::findOrFail($input['track_id']), [
            'mood' => 'Winning metadata edit', 'metadata_version' => $input['metadata_version'],
        ], User::findOrFail($input['actor_id']));
        $result = ['result' => 'edited', 'metadata_version' => $track->metadata_version];
    } else {
        $command = app(PublishTrack::class);
        $actor = User::findOrFail($input['actor_id']);
        $track = $input['intent'] === 'publish' ? $command->publishReviewed($input['review'], $actor)
            : $command->unpublishReviewed($input['review'], $actor);
        $result = ['result' => 'saved', 'status' => $track->status, 'publication_version' => $track->publication_version];
    }
} catch (ValidationException $error) {
    $result = ['result' => 'rejected', 'errors' => $error->errors()];
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
