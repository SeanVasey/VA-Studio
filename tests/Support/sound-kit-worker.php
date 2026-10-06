<?php

use App\Domain\Media\MalwareScanner;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\SoundKitDrafts;
use App\Domain\SoundKits\SoundKitIntake;
use App\Domain\SoundKits\SoundKitProcessor;
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
    throw new LogicException('Native synthetic kit worker only');
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
            throw new RuntimeException('Kit worker barrier timed out');
        }
        usleep(10000);
        clearstatcache();
    }
};
app()->instance(MalwareScanner::class, new class($input, $wait) extends MalwareScanner
{
    private int $calls = 0;

    public function __construct(private array $input, private Closure $wait) {}

    public function scan(string $path): array
    {
        if (++$this->calls === 3 && ($this->input['pause_scan'] ?? false)) {
            touch($this->input['directory'].'/scanned-'.$this->input['name']);
            ($this->wait)($this->input['directory'].'/release-scan');
        }

        return ['engine' => 'test-only', 'status' => 'clean', 'sha256' => hash_file('sha256', $path)];
    }
});
if ($input['require_mfa'] ?? false) {
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
}
$actor = User::findOrFail($input['actor_id']);
$mfaPrecheck = AdminMultiFactor::satisfiedBy($actor);
$locks = 0;
DB::listen(function ($query) use ($input, $directory, $name, $wait, &$locks): void {
    if (str_contains($query->sql, 'from `sound_kit_drafts`') && str_contains($query->sql, 'for update')) {
        $locks++;
        if (($input['pause'] ?? false) && $locks === ($input['operation'] === 'upload' ? 2 : 1)) {
            touch($directory.'/locked-'.$name);
            $wait($directory.'/release-'.$name);
        }
    }
});
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($directory.'/ready-'.$name, json_encode(['pid' => getmypid(), 'connection_id' => $connection], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$name);
try {
    $drafts = app(SoundKitDrafts::class);
    $result = match ($input['operation']) {
        'create' => $drafts->save(null, ['title' => 'Synthetic race kit', 'provenance' => 'Test generator'], $actor),
        'edit' => $drafts->save(SoundKitDraft::findOrFail($input['draft_id']), ['title' => 'Edited title', 'provenance' => 'Test generator', 'version' => $input['version']], $actor),
        'snapshot' => $drafts->snapshot($input['draft_id'], $actor),
        'upload' => app(SoundKitIntake::class)->handle($input['draft_id'], new UploadedFile($input['upload_path'], 'kit.zip', null, null, true), $input['version'], $actor),
        'retry' => app(SoundKitIntake::class)->retry($input['revision_id'], $actor),
        'process' => app(SoundKitProcessor::class)->handle($input['revision_id']),
    };
    $response = ['result' => 'saved', 'id' => is_array($result) ? null : $result->id, 'status' => is_array($result) ? null : $result->status];
} catch (AuthorizationException) {
    $response = ['result' => 'unauthorized'];
} catch (ValidationException) {
    $response = ['result' => 'rejected'];
}
echo json_encode($response + ['mfa_precheck' => $mfaPrecheck, 'pid' => getmypid(), 'connection_id' => $connection,
    'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
