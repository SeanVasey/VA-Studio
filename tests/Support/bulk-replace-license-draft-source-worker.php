<?php

use App\Domain\Rights\BulkReplaceLicenseDraftSource;
use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\ReviewedLicenseDraft;
use App\Domain\Rights\ReviewLicense;
use App\Domain\Rights\SaveLicenseTemplate;
use App\Domain\Rights\UpdateLicenseDraft;
use App\Models\User;
use Filament\Facades\Filament;
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
    throw new LogicException('Bulk source workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = getenv('VASEY_BULK_LICENSE_DIRECTORY');
$worker = getenv('VASEY_BULK_LICENSE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Bulk source worker barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
// Retain stale real models before either competing transaction starts.
// Strict apply uses only the original review; capture uses these identity/parent hints.
$actor = User::findOrFail($input['actor_id']);
$versions = array_map(fn ($id) => LicenseVersion::findOrFail($id), $input['version_ids']);
$version = isset($input['version_id']) ? LicenseVersion::findOrFail($input['version_id']) : null;
$template = isset($input['template_id']) ? LicenseTemplate::findOrFail($input['template_id']) : null;
if ($input['require_mfa']) {
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
}
$ready = json_encode(['connection_id' => $connection, 'pid' => getmypid(), 'retained_admin' => $actor->is_admin,
    'retained_verified_email' => $actor->email_verified_at !== null, 'retained_version_ids' => array_map(fn ($row) => $row->id, $versions)], JSON_THROW_ON_ERROR);
$temporary = $directory.'/ready-'.$worker.'.tmp';
if (file_put_contents($temporary, $ready) !== strlen($ready) || ! rename($temporary, $directory.'/ready-'.$worker)) {
    throw new RuntimeException('Cannot publish bulk source worker readiness.');
}
$wait($directory.'/start-'.$worker);
$paused = false;
$trace = [];
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$trace): void {
    if (! preg_match('/\Aselect\b/i', $query->sql)) {
        return;
    }
    if (! str_contains($query->sql, 'for update')) {
        if (str_contains($query->sql, 'from `audit_events`')) {
            $trace[] = ['kind' => 'audit', 'table' => 'audit_events', 'ids' => []];
        }

        return;
    }
    foreach (['users', 'license_templates', 'license_versions'] as $table) {
        if (! str_contains($query->sql, 'from `'.$table.'`')) {
            continue;
        }
        $ids = array_map('intval', array_filter($query->bindings, fn ($value) => is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value))));
        $trace[] = ['kind' => 'lock', 'table' => $table, 'ids' => array_values($ids)];
        if (! $paused && ($input['pause_table'] ?? null) === $table && in_array($input['pause_id'], $ids, true)) {
            $paused = true;
            file_put_contents($directory.'/locked-'.$worker, json_encode(['table' => $table, 'id' => $input['pause_id']], JSON_THROW_ON_ERROR));
            $wait($directory.'/release-'.$worker);
        }
    }
});
try {
    if (in_array($input['operation'], ['role', 'email', 'mfa'], true)) {
        DB::transaction(function () use ($input): void {
            User::query()->lockForUpdate()->findOrFail($input['actor_id']);
            DB::table('users')->where('id', $input['actor_id'])->update(match ($input['operation']) {
                'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], 'mfa' => ['app_authentication_secret' => null],
            });
        });
        $result = ['result' => 'withdrawn'];
    } elseif ($input['operation'] === 'capture') {
        $review = app(BulkReplaceLicenseDraftSource::class)->review($versions, $input['source'], $actor);
        $result = ['result' => 'captured', 'review' => $review];
    } elseif ($input['operation'] === 'bulk') {
        $changed = app(BulkReplaceLicenseDraftSource::class)->applyReviewed($input['review'], $actor);
        $result = ['result' => 'saved', 'applied' => $changed];
    } else {
        $saved = match ($input['operation']) {
            'single' => app(ReviewedLicenseDraft::class)->updateReviewed($input['review'], $input['data'], $actor),
            'legacy' => app(UpdateLicenseDraft::class)->handle($version, $input['data'], $actor),
            'submit' => app(ReviewLicense::class)->submit($version, $actor),
            'template' => app(SaveLicenseTemplate::class)->updateReviewed($input['review'], $input['data'], $actor),
            default => throw new LogicException('Unknown bulk source worker operation.'),
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
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'paused' => $paused,
    'trace' => $trace, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
