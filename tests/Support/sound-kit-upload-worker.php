<?php

use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\SoundKitUploads;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Native synthetic kit transport worker only');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
$directory = $input['directory'];
$name = $input['name'];
config(['filesystems.disks.local.root' => $input['private_root'], 'filesystems.disks.local.serve' => false]);
Storage::forgetDisk('local');
Queue::fake();
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Kit transport worker barrier timed out');
        }
        usleep(10000);
        clearstatcache();
    }
};
if ($input['require_mfa'] ?? false) {
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
}
$actor = User::findOrFail($input['actor_id']);
$mfaPrecheck = AdminMultiFactor::satisfiedBy($actor);
$paused = false;
DB::listen(function ($query) use ($input, $directory, $name, $wait, &$paused): void {
    if (! $paused && ($input['pause'] ?? false) && str_contains($query->sql, 'from `sound_kit_drafts`') && str_contains($query->sql, 'for update')) {
        $paused = true;
        touch($directory.'/locked-'.$name);
        $wait($directory.'/release-'.$name);
    }
});
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($directory.'/ready-'.$name, json_encode(['pid' => getmypid(), 'connection_id' => $connection], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$name);
try {
    $uploads = app(SoundKitUploads::class);
    $result = match ($input['operation']) {
        'start' => $uploads->start(SoundKitDraft::findOrFail($input['draft_id']), 1, $input['size'], $input['hash'], 'kit.zip', $actor),
        'append' => $uploads->append($input['session_id'], 0, new UploadedFile($input['upload_path'], 'part.bin', null, null, true), $actor),
        default => $uploads->{$input['operation']}($input['session_id'], $actor),
    };
    $response = ['result' => 'saved', 'id' => is_array($result) ? $result['id'] : $result->id];
} catch (AuthorizationException) {
    $response = ['result' => 'unauthorized'];
} catch (ValidationException) {
    $response = ['result' => 'rejected'];
}
echo json_encode($response + ['mfa_precheck' => $mfaPrecheck, 'pid' => getmypid(), 'connection_id' => $connection,
    'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
