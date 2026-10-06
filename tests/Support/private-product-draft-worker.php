<?php

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\CanonicalJson;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\PrivateProductDraftFixtures as Fixtures;

umask(0077);
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
    throw new LogicException('Private draft workers require an isolated native MySQL test environment.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
$directory = $input['directory'];
$name = $input['name'];
[$commandClass] = Fixtures::classes($input['kind']);
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Private draft worker barrier timed out.');
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
$snapshotted = false;
User::retrieved(function (User $row) use ($input, $directory, $name, $wait, &$paused, &$snapshotted): void {
    if ($row->id !== $input['actor_id'] || DB::transactionLevel() === 0) {
        return;
    }
    if (($input['snapshot_before_parent'] ?? false) && ! $snapshotted) {
        $snapshotted = true;
        // An adversarial framework callback establishes a repeatable-read snapshot before
        // the parent's current fence. Every subsequent semantic read must be locking.
        DB::table($input['kind'].'_draft_versions')->where('draft_id', $input['draft_id'])->get();
        touch($directory.'/snapshot-'.$name);
    }
    if (($input['pause'] ?? false) && ! $paused) {
        $paused = true;
        if ($input['pause_parent'] ?? false) {
            DB::table($input['kind'].'_drafts')->where('id', $input['draft_id'])->lockForUpdate()->first();
        }
        touch($directory.'/locked-'.$name);
        $wait($directory.'/release-'.$name);
    }
});
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($directory.'/ready-'.$name, json_encode(['connection_id' => $connection, 'pid' => getmypid()], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$name);
try {
    $command = app($commandClass);
    if ($input['operation'] === 'snapshot') {
        $snapshot = $command->snapshot($input['draft_id'], $actor);
        $response = ['result' => 'read', 'version' => $snapshot['version'], 'manifest_hash' => CanonicalJson::hash($snapshot['manifest'])];
    } else {
        $saved = $command->applyReviewed($input['review'], $actor);
        $response = ['result' => 'saved', 'draft_id' => (int) $saved->id, 'version' => $saved->version];
    }
} catch (ValidationException) {
    $response = ['result' => 'rejected'];
} catch (AuthorizationException) {
    $response = ['result' => 'unauthorized'];
}
echo json_encode($response + ['connection_id' => $connection, 'pid' => getmypid(), 'mfa_precheck' => $mfaPrecheck,
    'transaction_level' => DB::transactionLevel(), 'snapshot_established' => $snapshotted], JSON_THROW_ON_ERROR);
