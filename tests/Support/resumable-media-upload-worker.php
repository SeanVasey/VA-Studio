<?php

use App\Domain\Catalog\Models\Track;
use App\Domain\Media\ResumableMediaUploads;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Resumable upload workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false,
    'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = $input['directory'];
$name = $input['name'];
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Resumable upload barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$paused = false;
DB::listen(function ($query) use ($input, $directory, $wait, &$paused): void {
    if ($input['pause'] && ! $paused && preg_match('/\Aselect\b/i', $query->sql)
        && str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        touch($directory.'/first-locked-actor');
        $wait($directory.'/release-first');
    }
});
file_put_contents($directory.'/ready-'.$name, json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$name);
$actor = User::findOrFail($input['actor_id']);
$mfaPrecheck = null;
if ($input['require_mfa'] ?? false) {
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
    $mfaPrecheck = AdminMultiFactor::satisfiedBy($actor);
    if (! $mfaPrecheck) {
        throw new LogicException('The native MFA withdrawal fixture did not pass initial admission.');
    }
}
try {
    $service = app(ResumableMediaUploads::class);
    $value = match ($input['operation']) {
        'start' => $service->start(Track::findOrFail($input['track_id']), 'artwork', filesize($directory.'/chunk.bin'), hash_file('sha256', $directory.'/chunk.bin'), 'new-synthetic.png', $actor),
        'inspect' => $service->inspect($input['session_id'], $actor),
        'append' => $service->append($input['session_id'], 0, new UploadedFile($directory.'/chunk.bin', 'chunk.bin', null, UPLOAD_ERR_OK, true), $actor),
        'complete' => $service->complete($input['session_id'], $actor),
        'cancel' => $service->cancel($input['session_id'], $actor),
        default => throw new LogicException('Unknown resumable upload operation.'),
    };
    $result = ['result' => 'saved', 'asset_id' => $input['operation'] === 'complete' ? $value->id : null,
        'status' => $input['operation'] === 'complete' ? 'completed' : $value['status']];
} catch (ValidationException $error) {
    $result = ['result' => 'rejected', 'errors' => $error->errors()];
} catch (AuthorizationException) {
    $result = ['result' => 'unauthorized'];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(),
    'transaction_level' => DB::transactionLevel(), 'mfa_precheck' => $mfaPrecheck], JSON_THROW_ON_ERROR);
