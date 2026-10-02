<?php

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\ReadTrackPublicationManifest;
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
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
    throw new LogicException('Track publication manifest race workers require testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = getenv('VASEY_TRACK_PUBLICATION_MANIFEST_RACE_DIRECTORY');
$worker = getenv('VASEY_TRACK_PUBLICATION_MANIFEST_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Track publication manifest race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$isLock = fn (string $query, string $table) => preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `'.$table.'`') && str_contains($query, 'for update');
$barrierTable = $input['mode'] === 'authority' ? 'users' : 'tracks';
file_put_contents($directory.'/ready-'.$worker, (string) $connection);
$wait($directory.'/start-'.$worker);
$locked = false;
DB::listen(function ($query) use ($isLock, $barrierTable, $directory, $worker, $wait, &$locked): void {
    if (! $locked && $isLock($query->sql, $barrierTable)) {
        $locked = true;
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
    } elseif ($input['operation'] === 'capture') {
        $manifest = app(ReadTrackPublicationManifest::class)->handle($input['track_id'], User::findOrFail($input['actor_id']));
        $result = ['result' => 'captured', 'hash' => $manifest->hash(), 'actor_id' => $manifest->actorId(),
            'track' => array_intersect_key($manifest->payload()['track'], array_flip(['metadata_version', 'publication_version', 'status', 'mood']))];
    } else {
        $actor = User::findOrFail($input['actor_id']);
        $track = Track::findOrFail($input['track_id']);
        if ($input['operation'] === 'edit') {
            app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Committed current metadata', 'metadata_version' => $input['metadata_version']], $actor);
        } else {
            app(PublishTrack::class)->handle($track, $actor);
        }
        $result = ['result' => 'saved'];
    }
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
} catch (ValidationException $error) {
    $result = ['result' => 'rejected', 'errors' => $error->errors()];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
