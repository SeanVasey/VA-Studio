<?php

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Media\MalwareScanner;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\MediaProcessor;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestOnlyMediaScanner;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::connection()->getDriverName() !== 'mysql') {
    throw new LogicException('Publication apply race workers require testing MySQL.');
}
$input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
config($input['config']);
config(['filesystems.disks.local.root' => $input['media_root'], 'filesystems.disks.local.serve' => false, 'filesystems.disks.local.visibility' => 'private']);
Storage::forgetDisk('local');
if ($input['operation'] === 'media') {
    // Explicit synthetic scanner for lock tests; genuine scanner acceptance remains native CI.
    $app->instance(MalwareScanner::class, new TestOnlyMediaScanner);
}
Queue::fake();
Carbon::setTestNow($input['now']);
CarbonImmutable::setTestNow($input['now']);
DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$directory = getenv('VASEY_TRACK_PUBLICATION_APPLY_RACE_DIRECTORY');
$worker = getenv('VASEY_TRACK_PUBLICATION_APPLY_RACE_WORKER');
$connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$wait = function (string $path): void {
    $deadline = microtime(true) + 20;
    while (! is_file($path)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Publication apply race barrier timed out.');
        }
        usleep(10000);
        clearstatcache();
    }
};
$locks = [];
$paused = false;
DB::listen(function ($query) use ($input, $directory, $worker, $wait, &$paused, &$locks): void {
    if (preg_match('/\Aselect\b.*\bfrom `([a-z_]+)`/i', $query->sql, $match)) {
        $kind = str_contains($query->sql, 'for update') ? 'exclusive' : (str_contains($query->sql, 'lock in share mode') ? 'shared' : null);
        if ($kind !== null) {
            $locks[] = $match[1].':'.$kind;
            if (! $paused && ($input['pause_table'] ?? null) === $match[1] && $kind === 'exclusive') {
                $paused = true;
                touch($directory.'/locked-'.$worker);
                $wait($directory.'/commit');
            }
        }
    }
});
file_put_contents($directory.'/ready-'.$worker, (string) $connection);
$wait($directory.'/start-'.$worker);
try {
    $actor = User::findOrFail($input['actor_id']);
    $track = Track::findOrFail($input['track_id']);
    $result = match ($input['operation']) {
        'apply' => (function () use ($input, $actor): string {
            app(PublishTrack::class)->publishManifestReviewed($input['review'], $actor);

            return 'published';
        })(),
        'authority' => (function () use ($actor): string {
            DB::transaction(function () use ($actor): void {
                $current = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $current->is_admin = false;
                $current->save();
            });

            return 'saved';
        })(),
        'metadata' => (function () use ($input, $track, $actor): string {
            app(SaveTrackMetadata::class)->handle($track, ['mood' => 'Concurrent current evidence', 'metadata_version' => $input['metadata_version']], $actor);

            return 'saved';
        })(),
        'offer' => (function () use ($input, $actor): string {
            $offer = Offer::findOrFail($input['offer_id']);
            app(PublishOffer::class)->handle($offer, $actor);

            return 'saved';
        })(),
        'scope' => (function () use ($input, $actor): string {
            app(ManageRightsScope::class)->block($input['scope_id'], true, 0, 'SYNTHETIC-PUBLICATION-RACE', $actor);

            return 'saved';
        })(),
        'media' => app(MediaProcessor::class)->handle($input['run_id'])->status === 'completed' ? 'saved' : 'media-failed',
        'finalize' => app(FinalizeTestPayment::class)->handle($input['payment_id']),
    };
    $output = ['result' => $result];
} catch (MediaFailure $error) {
    $output = ['result' => 'media-failed', 'code' => $error->failureCode];
} catch (AuthorizationException) {
    $output = ['result' => 'denied'];
} catch (ValidationException $error) {
    $output = ['result' => 'rejected', 'errors' => $error->errors()];
}
echo json_encode($output + ['connection_id' => $connection, 'pid' => getmypid(), 'locks' => $locks], JSON_THROW_ON_ERROR);
