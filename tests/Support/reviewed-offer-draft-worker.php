<?php

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\ReviewedOfferDraft;
use App\Domain\Catalog\SaveOfferDraft;
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
    throw new LogicException('Reviewed offer workers require disposable testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
$directory = getenv('VASEY_REVIEWED_OFFER_DIRECTORY');
$worker = getenv('VASEY_REVIEWED_OFFER_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Reviewed offer barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
// Retain the real actor and resource before the competing mutation starts.
// The review was issued to this actor before either worker was launched.
$actor = User::findOrFail($input['actor_id']);
$track = Track::findOrFail($input['track_id']);
$offer = Offer::findOrFail($input['offer_id']);
$ready = json_encode(['connection_id' => $connection, 'pid' => getmypid(), 'retained_admin' => $actor->is_admin, 'retained_verified_email' => $actor->email_verified_at !== null], JSON_THROW_ON_ERROR);
$temporary = $directory.'/ready-'.$worker.'.tmp';
// The parent decodes as soon as ready-N exists; publish only the complete payload.
if (file_put_contents($temporary, $ready) !== strlen($ready) || ! rename($temporary, $directory.'/ready-'.$worker)) {
    throw new RuntimeException('Cannot publish reviewed offer worker readiness.');
}
if ($input['require_mfa']) {
    $panel = Filament::getPanel('admin');
    $panel->multiFactorAuthentication($panel->getMultiFactorAuthenticationProviders(), isRequired: true);
}
$wait($directory.'/start-'.$worker);
$paused = false;
$locks = [];
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$locks): void {
    if (! preg_match('/\Aselect\b/i', $query->sql) || ! str_contains($query->sql, 'for update')) {
        return;
    }
    foreach (['users', 'tracks', 'offers'] as $table) {
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
    if (in_array($input['operation'], ['role', 'email', 'mfa'], true)) {
        DB::transaction(function () use ($input): void {
            User::query()->lockForUpdate()->findOrFail($input['actor_id']);
            DB::table('users')->where('id', $input['actor_id'])->update(match ($input['operation']) {
                'role' => ['is_admin' => false], 'email' => ['email_verified_at' => null], default => ['app_authentication_secret' => null]
            });
        });
        $result = ['result' => 'withdrawn'];
    } else {
        match ($input['operation']) {
            'edit' => app(ReviewedOfferDraft::class)->updateReviewed($input['review'], $input['data'], $actor),
            'legacy' => app(SaveOfferDraft::class)->handle($offer, $input['data'], $actor),
            'publish' => app(PublishOffer::class)->handle($offer, $actor),
            'deactivate' => app(DeactivateOffer::class)->handle($offer, $actor),
            default => throw new LogicException('Unknown reviewed offer operation.'),
        };
        $result = ['result' => 'saved', 'row' => $offer->fresh()->getAttributes()];
    }
} catch (AuthorizationException) {
    $result = ['result' => 'denied'];
} catch (ValidationException $error) {
    $result = ['result' => 'blocked', 'errors' => $error->errors()];
} catch (QueryException $error) {
    $result = ['result' => 'database-failed', 'sqlstate' => $error->errorInfo[0] ?? null, 'driver_code' => $error->errorInfo[1] ?? null];
}
echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'paused' => $paused, 'locks' => $locks, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
