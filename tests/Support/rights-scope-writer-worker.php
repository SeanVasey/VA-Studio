<?php

use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveRightsDeclaration;
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
    throw new LogicException('Rights writer workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false,
    'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$panel = Filament::getPanel('admin');
Filament::setCurrentPanel($panel);
$panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: $input['require_mfa'] ?? false);
$directory = getenv('VASEY_RIGHTS_SCOPE_WRITER_DIRECTORY');
$worker = getenv('VASEY_RIGHTS_SCOPE_WRITER_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Rights writer barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
// Each supported caller retains the genuine old actor and server review before either process starts.
$actor = User::findOrFail($input['actor_id']);
$record = isset($input['declaration_id']) ? RightsDeclaration::findOrFail($input['declaration_id']) : null;
file_put_contents($directory.'/ready-'.$worker, json_encode(['connection_id' => $connection, 'pid' => getmypid(),
    'retained_admin' => $actor->is_admin, 'retained_verified_email' => $actor->email_verified_at !== null,
    'retained_enrollment' => $actor->getAppAuthenticationSecret() !== null], JSON_THROW_ON_ERROR));
$wait($directory.'/start-'.$worker);
$paused = false;
$locks = [];
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$locks): void {
    if (! preg_match('/\Aselect\b/i', $query->sql) || ! str_contains($query->sql, 'for update')) {
        return;
    }
    foreach (['users', 'tracks', 'offers', 'rights_scopes', 'rights_declarations'] as $table) {
        if (! str_contains($query->sql, 'from `'.$table.'`')) {
            continue;
        }
        $ids = array_map('intval', array_filter($query->bindings, fn ($value) => is_int($value)
            || (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value))));
        $locks[] = ['table' => $table, 'ids' => array_values($ids)];
        if (! $paused && ($input['pause_table'] ?? null) === $table
            && in_array($input['pause_id'], $ids, true)) {
            $paused = true;
            file_put_contents($directory.'/locked-'.$worker, json_encode(['table' => $table, 'id' => $input['pause_id']], JSON_THROW_ON_ERROR));
            $wait($directory.'/release-'.$worker);
        }
    }
});
try {
    if ($input['operation'] === 'scope') {
        $saved = $input['writer'] === 'link'
            ? app(ManageRightsScope::class)->link($input['scope_id'], $input['revision_id'], 'SYNTHETIC-RACE-LINK', $actor)
            : app(ManageRightsScope::class)->block($input['scope_id'], true, 0, 'SYNTHETIC-RACE-BLOCK', $actor);
        $result = ['result' => 'scope-saved', 'row' => $saved->fresh()->getAttributes()];
    } else {
        $saved = app(SaveRightsDeclaration::class)->updateReviewed($input['review'], $input['data'], $actor);
        $result = ['result' => 'rights-saved', 'row' => $saved->fresh()->getAttributes()];
    }
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
} catch (ValidationException $error) {
    $result = ['result' => 'blocked', 'errors' => $error->errors()];
} catch (QueryException $error) {
    // Keep a concrete lock/SQL failure diagnostic without recording SQL text or its bindings.
    $result = ['result' => 'database-failed', 'sqlstate' => $error->errorInfo[0] ?? null,
        'driver_code' => $error->errorInfo[1] ?? null];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(),
    'paused' => $paused, 'locks' => $locks, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
