<?php

use App\Domain\Rights\CollaboratorSplits;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_SPLIT_RACE_DIRECTORY');
$worker = getenv('VASEY_SPLIT_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Collaborator split race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$row = ['track_id' => $input['track_id'], 'collaborator_id' => $input['collaborator_id'], 'split_type' => 'master', 'share_bps' => $input['share_bps']];
try {
    if ($input['operation'] === 'raw') {
        // Bypass the domain service entirely: only the database guards stand between two writers and 10000.
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        // Establish this transaction's snapshot first: a plain read after the other writer commits still shows the old total.
        $seen = (int) DB::table('track_collaborator_splits')->where('track_id', $input['track_id'])->where('split_type', 'master')->sum('share_bps');
        file_put_contents($directory.'/ready-'.$worker, (string) $connection);
        $wait($directory.'/start-'.$worker);
        try {
            DB::table('track_collaborator_splits')->insert($row + ['created_at' => now(), 'updated_at' => now()]);
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
            $stale = (int) DB::table('track_collaborator_splits')->where('track_id', $input['track_id'])->where('split_type', 'master')
                ->where('collaborator_id', '<>', $input['collaborator_id'])->sum('share_bps');
            DB::commit();
            $result = ['result' => 'saved', 'snapshot_total_bps' => $seen, 'plain_read_of_others_bps' => $stale];
        } catch (QueryException $exception) {
            $plain = (int) DB::table('track_collaborator_splits')->where('track_id', $input['track_id'])->where('split_type', 'master')->sum('share_bps');
            DB::rollBack();
            $result = ['result' => 'refused_by_database', 'message' => $exception->getMessage(), 'snapshot_total_bps' => $seen, 'plain_read_bps' => $plain];
        }
    } else {
        $isTrackLock = fn (string $query) => preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `tracks`') && str_contains($query, 'for update');
        DB::connection()->beforeExecuting(function ($query) use ($isTrackLock, $directory, $worker, $connection, $wait): void {
            if ($isTrackLock($query)) {
                file_put_contents($directory.'/ready-'.$worker, (string) $connection);
                $wait($directory.'/start-'.$worker);
            }
        });
        DB::listen(function ($query) use ($isTrackLock, $directory, $worker, $wait): void {
            if ($isTrackLock($query->sql)) {
                // Hold the acquired track row until the parent has seen the other editor waiting on it.
                touch($directory.'/locked-'.$worker);
                $wait($directory.'/commit');
            }
        });
        $split = app(CollaboratorSplits::class)->addSplit($row, User::findOrFail($input['actor_id']));
        $result = ['result' => 'saved', 'split_id' => $split->id];
    }
} catch (ValidationException $exception) {
    $result = ['result' => 'rejected', 'errors' => $exception->errors()];
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
