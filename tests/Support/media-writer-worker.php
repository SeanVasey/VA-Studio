<?php

use App\Application\Media\IngestMediaUpload;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\QueueMediaProcessing;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\MediaFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Rights writer workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false,
    'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$panel = Filament::getPanel('admin');
Filament::setCurrentPanel($panel);
$panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $input['require_mfa'] ?? false);
$directory = getenv('VASEY_MEDIA_WRITER_DIRECTORY');
$worker = getenv('VASEY_MEDIA_WRITER_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Rights writer barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
// Each supported caller retains the genuine old actor and server review before either process starts.
$actor = User::findOrFail($input['actor_id']);
MediaFixtures::configure();
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connection, 'pid' => getmypid(),
    'retained_admin' => $actor->is_admin, 'retained_verified_email' => $actor->email_verified_at !== null,
    'retained_enrollment' => $actor->getAppAuthenticationSecret() !== null], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$worker);
$paused = false;
$seen = 0;
$locks = [];
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$locks, &$seen): void {
    if (! preg_match('/\Aselect\b/i', $query->sql) || ! str_contains($query->sql, 'for update')) {
        return;
    }
    foreach (['users', 'tracks', 'media_assets', 'media_processing_runs', 'stems_recordings'] as $table) {
        if (! str_contains($query->sql, 'from `'.$table.'`')) {
            continue;
        }
        $ids = array_map('intval', array_filter($query->bindings, fn ($value) => is_int($value)
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value))));
        $locks[] = ['table' => $table, 'ids' => array_values($ids)];
        if (($input['pause_table'] ?? null) === $table && in_array($input['pause_id'], $ids, true)) {
            $seen++;
        }
        if (! $paused && ($input['pause_table'] ?? null) === $table
            && in_array($input['pause_id'], $ids, true) && $seen === ($input['pause_nth'] ?? 1)) {
            $paused = true;
            file_put_contents($directory.'/locked-'.$worker, json_encode(['table' => $table, 'id' => $input['pause_id']], JSON_THROW_ON_ERROR));
            $wait($directory.'/release-'.$worker);
        }
    }
});
try {
    if ($input['operation'] === 'publish') {
        app(PublishTrack::class)->publishReviewed($input['review'], $actor);
        $result = ['result' => 'published'];
    } else {
        $saved = match ($input['writer']) {
            'queue' => app(QueueMediaProcessing::class)->handle(MediaAsset::findOrFail($input['source_id']), $actor),
            'intake' => app(IngestMediaUpload::class)->handle(Track::findOrFail($input['track_id']), UploadedFile::fake()->createWithContent('synthetic.png', MediaFixtures::png()), 'artwork', $actor),
            'bind' => app(BindStemsToRecording::class)->handle(MediaAsset::findOrFail($input['stems_id']), $input['data'], $actor),
            'complete', 'failure' => app(MediaProcessor::class)->handle($input['run_id']),
        };
        $result = ['result' => 'media-saved', 'row' => $saved->fresh()->getAttributes()];
    }
} catch (MediaFailure $error) {
    $result = ['result' => 'media-failed', 'code' => $error->failureCode];
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
} catch (ValidationException $error) {
    $result = ['result' => 'blocked', 'errors' => $error->errors()];
} catch (QueryException $error) {
    // Keep a concrete lock/SQL failure diagnostic without recording SQL text or its bindings.
    $result = ['result' => 'database-failed', 'sqlstate' => $error->errorInfo[0] ?? null,
        'driver_code' => $error->errorInfo[1] ?? null];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(),
    'paused' => $paused, 'locks' => $locks, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
