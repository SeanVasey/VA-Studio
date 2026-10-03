<?php

use App\Domain\Media\Models\MediaAsset;
use App\Domain\Media\Models\StemsRecording;
use App\Domain\Media\BindStemsToRecording;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = getenv('VASEY_STEMS_RACE_DIRECTORY');
$worker = getenv('VASEY_STEMS_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$callerTransaction = $input['caller_transaction'] ?? false;
$snapshotBefore = null;
if ($callerTransaction) {
    DB::beginTransaction();
    $snapshotBefore = StemsRecording::where('stems_asset_id', $input['stems_id'])->count();
}
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Recording race barrier timed out.');
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
    $binding = app(BindStemsToRecording::class)->handle(MediaAsset::findOrFail($input['stems_id']), [
        'verification_reference' => $input['verification_reference'],
    ] + $input['data'], User::findOrFail($input['actor_id']));
    $result = ['result' => 'saved', 'binding_id' => $binding->id];
} catch (ValidationException $exception) {
    $result = ['result' => 'rejected', 'errors' => $exception->errors()];
}
$callerTransactionLevel = DB::transactionLevel();
$snapshotAfter = null;
if ($callerTransaction) {
    $snapshotAfter = StemsRecording::where('stems_asset_id', $input['stems_id'])->count();
    DB::commit();
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(),
    'caller_transaction_level' => $callerTransactionLevel, 'transaction_level' => DB::transactionLevel(),
    'snapshot_before' => $snapshotBefore, 'snapshot_after' => $snapshotAfter], JSON_THROW_ON_ERROR);
