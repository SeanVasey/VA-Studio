<?php

use App\Domain\Commerce\Policy\ReviewProductionTrackPolicy;
use App\Domain\Commerce\Policy\SaveProductionTrackPolicy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Production policy race workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = getenv('VASEY_PRODUCTION_POLICY_RACE_DIRECTORY');
$worker = getenv('VASEY_PRODUCTION_POLICY_RACE_WORKER');
if (! is_string($directory) || ! str_starts_with($directory, storage_path('framework/testing/production-policy-'))
    || ! is_dir($directory) || is_link($directory) || (fileperms($directory) & 07777) !== 0700 || ! in_array($worker, ['0', '1'], true)) {
    throw new LogicException('Invalid private policy race directory.');
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$actor = User::findOrFail($input['actor_id']);
$publish = function (string $name, array $value) use ($directory): void {
    $bytes = json_encode($value, JSON_THROW_ON_ERROR);
    if (file_put_contents($directory.'/'.$name.'.tmp', $bytes) !== strlen($bytes)
        || ! rename($directory.'/'.$name.'.tmp', $directory.'/'.$name)) {
        throw new RuntimeException('Cannot publish complete policy race barrier.');
    }
};
$publish('ready-'.$worker, ['connection_id' => $connection, 'pid' => getmypid(), 'retained_admin' => $actor->is_admin,
    'retained_verified_email' => $actor->email_verified_at !== null]);
$wait = function (string $name) use ($directory): void {
    $deadline = microtime(true) + 20;
    do {
        clearstatcache();
        if (is_file($directory.'/'.$name)) {
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Production policy race barrier timed out.');
};
$wait('start-'.$worker);
$paused = false;
$locks = [];
$preFenceRevision = null;
if (isset($input['pre_fence_read_policy_id'])) {
    // Deliberately establish an old REPEATABLE READ snapshot from the authority
    // retrieval callback before the resource lock. This is not a lock barrier.
    User::retrieved(function (User $current) use ($actor, $input, &$preFenceRevision): void {
        if ($current->id === $actor->id && $preFenceRevision === null && DB::transactionLevel() === 1) {
            $row = DB::table('production_track_policy_drafts')->where('id', $input['pre_fence_read_policy_id'])->first();
            $preFenceRevision = $row?->revision;
        }
    });
}
DB::listen(function ($query) use ($input, $worker, $publish, $wait, &$paused, &$locks): void {
    if (! preg_match('/\Aselect\b/i', $query->sql) || ! str_contains($query->sql, 'for update')) {
        return;
    }
    foreach (['users', 'production_track_policy_drafts', 'production_track_policy_versions'] as $table) {
        if (! str_contains($query->sql, 'from `'.$table.'`')) {
            continue;
        }
        $locks[] = ['table' => $table, 'bindings' => $query->bindings];
        if (! $paused && ($input['pause_table'] ?? null) === $table) {
            $paused = true;
            $publish('locked-'.$worker, ['connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'table' => $table]);
            $wait('release-'.$worker);
        }
    }
});
try {
    if ($input['operation'] === 'withdraw') {
        DB::transaction(function () use ($actor): void {
            DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
            DB::table('users')->where('id', $actor->id)->update(['is_admin' => false]);
        });
        $status = 'withdrawn';
    } elseif ($input['operation'] === 'save') {
        app(SaveProductionTrackPolicy::class)->applyReviewed($input['review'], $actor);
        $status = 'saved';
    } elseif ($input['operation'] === 'acknowledge') {
        app(ReviewProductionTrackPolicy::class)->applyReviewed($input['review'], $input['reference'], $actor);
        $status = 'acknowledged';
    } else {
        throw new LogicException('Unknown isolated policy race operation.');
    }
} catch (AuthorizationException) {
    $status = 'denied';
} catch (ValidationException) {
    $status = 'blocked';
}
echo json_encode(['status' => $status, 'connection_id' => $connection, 'pid' => getmypid(), 'paused' => $paused,
    'transaction_level' => DB::transactionLevel(), 'locks' => $locks, 'pre_fence_revision' => $preFenceRevision], JSON_THROW_ON_ERROR);
