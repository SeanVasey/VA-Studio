<?php

use App\Domain\Media\BindStemsToRecording;
use App\Domain\Media\MediaProcessor;
use App\Domain\Media\Models\MediaAsset;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\MediaFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Recording preview workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false,
    'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
MediaFixtures::configure();
$directory = getenv('VASEY_RECORDING_PREVIEW_DIRECTORY');
$operation = getenv('VASEY_RECORDING_PREVIEW_OPERATION');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Recording preview barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$isTrackLock = fn (string $query) => preg_match('/\Aselect\b/i', $query)
    && str_contains($query, 'from `tracks`') && str_contains($query, 'for update');
$paused = false;
DB::listen(function ($query) use ($input, $operation, $directory, $isTrackLock, $wait, &$paused): void {
    if ($operation === 'processor' && ($input['pause_at_track'] ?? false) && ! $paused && $isTrackLock($query->sql)) {
        $paused = true;
        touch($directory.'/processor-locked-track');
        $wait($directory.'/release-processor');
    }
});
file_put_contents($directory.'/ready-'.$operation, json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$operation);
if ($operation === 'processor') {
    $run = app(MediaProcessor::class)->handle($input['run_id']);
    $result = ['result' => 'processed', 'run_id' => $run->id, 'status' => $run->status,
        'source_asset_id' => $run->source_asset_id, 'output_asset_ids' => $run->output_asset_ids];
} elseif ($operation === 'binding') {
    $callerTransaction = $input['caller_transaction'];
    $snapshotBefore = null;
    if ($callerTransaction) {
        DB::beginTransaction();
        $snapshotBefore = MediaAsset::where('track_id', $input['track_id'])->where('role', 'preview_tagged')
            ->where('status', 'ready')->latest('id')->value('id');
    }
    try {
        $binding = app(BindStemsToRecording::class)->handle(MediaAsset::findOrFail($input['stems_id']),
            $input['data'], User::findOrFail($input['actor_id']));
        $result = ['result' => 'saved', 'binding_id' => $binding->id, 'preview_asset_id' => $binding->preview_asset_id];
    } catch (ValidationException $exception) {
        $result = ['result' => 'rejected', 'errors' => $exception->errors()];
    }
    $result['caller_transaction_level'] = DB::transactionLevel();
    $result['snapshot_before'] = $snapshotBefore;
    $result['snapshot_after'] = null;
    if ($callerTransaction) {
        $result['snapshot_after'] = MediaAsset::where('track_id', $input['track_id'])->where('role', 'preview_tagged')
            ->where('status', 'ready')->latest('id')->value('id');
        DB::commit();
    }
} else {
    throw new LogicException('Unknown recording preview operation.');
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(),
    'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
