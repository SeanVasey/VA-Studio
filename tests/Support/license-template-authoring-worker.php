<?php

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Template authoring workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = getenv('VASEY_TEMPLATE_WRITER_DIRECTORY');
$worker = getenv('VASEY_TEMPLATE_WRITER_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Template authoring barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
// Retain the real actor and resource before the competing mutation starts.
// The review was issued to this actor before either worker was launched.
$actor = User::findOrFail($input['actor_id']);
$template = isset($input['template_id']) ? LicenseTemplate::findOrFail($input['template_id']) : null;
$version = isset($input['version_id']) ? LicenseVersion::findOrFail($input['version_id']) : null;
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connection, 'pid' => getmypid(), 'retained_admin' => $actor->is_admin, 'retained_verified_email' => $actor->email_verified_at !== null], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$worker);
$paused = false;
$locks = [];
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$locks): void {
    if (! preg_match('/\Aselect\b/i', $query->sql) || ! str_contains($query->sql, 'for update')) {
        return;
    }
    foreach (['users', 'license_templates', 'license_versions'] as $table) {
        if (! str_contains($query->sql, 'from `'.$table.'`')) {
            continue;
        }
        $ids = array_map('intval', array_filter($query->bindings, fn ($value) => is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value))));
        $locks[] = ['table' => $table, 'ids' => array_values($ids), 'sql' => $query->sql];
        if (! $paused && ($input['pause_table'] ?? null) === $table && in_array($input['pause_id'], $ids, true)) {
            $paused = true;
            file_put_contents($directory.'/locked-'.$worker, json_encode(['table' => $table, 'id' => $input['pause_id']], JSON_THROW_ON_ERROR));
            $wait($directory.'/release-'.$worker);
        }
    }
});
try {
    if ($input['operation'] === 'withdraw') {
        DB::transaction(function () use ($input): void {
            User::query()->lockForUpdate()->findOrFail($input['actor_id']);
            DB::table('users')->where('id', $input['actor_id'])->update(['is_admin' => false]);
        });
        $result = ['result' => 'withdrawn'];
    } elseif ($input['operation'] === 'submit') {
        $saved = app(ReviewLicense::class)->submit($version, $actor);
        $result = ['result' => 'submitted', 'row' => $saved->fresh()->getAttributes()];
    } else {
        $saved = match ($input['operation']) {
            'create' => app(SaveLicenseTemplate::class)->create($input['data'], $actor),
            'edit' => app(SaveLicenseTemplate::class)->updateReviewed($input['review'], $input['data'], $actor),
            default => throw new LogicException('Unknown template authoring operation.'),
        };
        $result = ['result' => 'saved', 'row' => $saved->fresh()->getAttributes()];
    }
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
} catch (ValidationException $error) {
    $result = ['result' => 'blocked', 'errors' => $error->errors()];
} catch (QueryException $error) {
    $result = ['result' => 'database-failed', 'sqlstate' => $error->errorInfo[0] ?? null, 'driver_code' => $error->errorInfo[1] ?? null];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'paused' => $paused, 'locks' => $locks, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
