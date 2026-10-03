<?php

use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\ReadTrackPublicationManifest;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Domain\Rights\SaveRightsDeclaration;
use App\Domain\Rights\VerifyRightsDeclaration;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\ExclusiveSelectionFixtures;

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
$directory = getenv('VASEY_RIGHTS_WRITER_DIRECTORY');
$worker = getenv('VASEY_RIGHTS_WRITER_WORKER');
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
$offer = isset($input['offer_id']) ? Offer::findOrFail($input['offer_id']) : null;
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
    foreach (['users', 'tracks', 'rights_declarations'] as $table) {
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
    if ($input['operation'] === 'withdraw') {
        DB::transaction(function () use ($input): void {
            User::query()->lockForUpdate()->findOrFail($input['actor_id']);
            DB::table('users')->where('id', $input['actor_id'])->update([$input['field'] => $input['value']]);
        });
        $result = ['result' => 'withdrawn'];
    } elseif ($input['operation'] === 'capture') {
        $manifest = app(ReadTrackPublicationManifest::class)->handle($input['track_id'], $actor);
        $result = ['result' => 'captured', 'hash' => $manifest->hash(), 'track_id' => $input['track_id']];
    } elseif ($input['operation'] === 'legacy-offer') {
        $revision = app(PublishOffer::class)->handle($offer, $actor);
        $result = ['result' => 'published', 'revision_id' => $revision->id, 'rights_id' => $revision->rights_declaration_id];
    } elseif ($input['operation'] === 'exclusive-writer') {
        ExclusiveSelectionFixtures::configure();
        $saved = $input['writer'] === 'prepare'
            ? app(PrepareExclusiveOffer::class)->handle($offer, $input['scope_id'], 'SYNTHETIC-EXCLUSIVE-RACE', $actor)
            : app(ActivateExclusiveOffer::class)->handle($offer, $input['revision_id'], $actor);
        $result = ['result' => 'exclusive-saved', 'row' => $saved->fresh()->getAttributes()];
    } elseif ($input['operation'] === 'catalog-writer') {
        $saved = match ($input['writer']) {
            'metadata' => app(SaveTrackMetadata::class)->handle(Track::findOrFail($input['track_id']), $input['data'], $actor),
            'draft' => app(SaveOfferDraft::class)->handle($offer, $input['data'], $actor),
            'deactivate' => app(DeactivateOffer::class)->handle($offer, $actor),
            default => throw new LogicException('Unknown participating catalog writer.'),
        };
        $result = ['result' => 'catalog-saved', 'row' => $saved->fresh()->getAttributes()];
    } else {
        $saved = match ($input['operation']) {
            'create' => app(SaveRightsDeclaration::class)->create($input['data'], $actor),
            'edit', 'retarget' => app(SaveRightsDeclaration::class)->updateReviewed($input['review'], $input['data'], $actor),
            'verify' => app(VerifyRightsDeclaration::class)->verifyReviewed($input['review'], $actor),
            default => throw new LogicException('Unknown rights writer operation.'),
        };
        $result = ['result' => 'saved', 'row' => $saved->fresh()->getAttributes()];
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
