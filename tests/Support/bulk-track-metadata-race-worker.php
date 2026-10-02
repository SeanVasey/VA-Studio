<?php

use App\Domain\Catalog\BulkUpdateTrackMetadata;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_BULK_METADATA_RACE_DIRECTORY');
$worker = getenv('VASEY_BULK_METADATA_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Bulk metadata race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$isLock = fn (string $query, string $table) => preg_match('/\Aselect\b/i', $query) && str_contains($query, 'from `'.$table.'`') && str_contains($query, 'for update');
if ($input['mode'] === 'overlap') {
    $ready = false;
    $locked = false;
    DB::connection()->beforeExecuting(function ($query) use ($isLock, $directory, $worker, $connection, $wait, &$ready): void {
        if (! $ready && $isLock($query, 'tracks')) {
            $ready = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start');
        }
    });
    DB::listen(function ($query) use ($isLock, $directory, $worker, $wait, &$locked): void {
        if (! $locked && $isLock($query->sql, 'tracks')) {
            $locked = true;
            // Pause after the entire sorted batch is acquired; the parent observes the actual shared-row wait.
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
} else {
    file_put_contents($directory.'/ready-'.$worker, (string) $connection);
    $wait($directory.'/start-'.$worker);
    $locked = false;
    DB::listen(function ($query) use ($isLock, $directory, $worker, $wait, &$locked): void {
        if (! $locked && $isLock($query->sql, 'users')) {
            $locked = true;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
}
try {
    if ($input['operation'] === 'withdraw') {
        DB::transaction(function () use ($input): void {
            $actor = User::query()->lockForUpdate()->findOrFail($input['actor_id']);
            $actor->is_admin = false;
            $actor->save();
        });
        $result = ['result' => 'withdrawn'];
    } else {
        $result = ['result' => 'saved'] + app(BulkUpdateTrackMetadata::class)->apply($input['review'], User::findOrFail($input['actor_id']));
    }
} catch (ValidationException $error) {
    $result = ['result' => 'rejected', 'errors' => $error->errors()];
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR);
