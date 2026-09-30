<?php

use App\Domain\Media\MalwareScanner;
use App\Domain\SiteBuilder\SiteContent;
use App\Domain\SiteBuilder\SiteImageProcessor;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\TestOnlyMediaScanner;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('CMS races require test MySQL.');
    }
    $directory = getenv('VASEY_SITE_RACE_DIRECTORY');
    $worker = getenv('VASEY_SITE_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing CMS race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 32, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'] ?? null, ['publish', 'rollback', 'run_schedule', 'cancel_schedule', 'process_site_image', 'create_site_release'], true)) {
        throw new LogicException('Unsupported CMS race operation.');
    }
    $table = getenv('VASEY_SITE_RACE_LOCK_TABLE') ?: 'site_publications';
    if (! in_array($table, ['site_publications', 'site_images'], true)) {
        throw new LogicException('Unsupported CMS race lock.');
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $wait = function (string ...$paths): void {
        $deadline = microtime(true) + 30;
        do {
            foreach ($paths as $path) {
                clearstatcache(true, $path);
                if (is_file($path)) {
                    return;
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('CMS worker barrier timed out.');
    };
    $isPublicationLock = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql)
        && str_contains($sql, 'from `'.$table.'`') && str_contains($sql, 'for update');
    // A worker can let its first locks pass, so the race happens at a later lock, such as an image's completion rather than its claim.
    $skip = (int) ($input['skip_locks'] ?? 0);
    $locks = 0;
    $armed = false;
    DB::connection()->beforeExecuting(function ($query) use ($isPublicationLock, $directory, $worker, $connection, $wait, $skip, &$locks, &$armed): void {
        if ($isPublicationLock($query) && ++$locks > $skip) {
            $armed = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start', $directory.'/start-'.$worker);
        }
    });
    DB::listen(function ($query) use ($isPublicationLock, $directory, $worker, $wait, &$armed): void {
        if ($armed && $isPublicationLock($query->sql)) {
            $armed = false;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
    try {
        // Share the test's private storage in every operation: processing writes image files, and publishing and the
        // scheduler check a release's image files before they lock.
        $root = getenv('VASEY_SITE_RACE_STORAGE_ROOT');
        if (! is_string($root) || ! is_dir($root)) {
            throw new LogicException('Missing site image storage root.');
        }
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');
        $site = app(SiteContent::class);
        if ($input['operation'] === 'process_site_image') {
            // The synthetic scanner, which the testing environment alone accepts.
            app()->instance(MalwareScanner::class, new TestOnlyMediaScanner);
            $image = app(SiteImageProcessor::class)->handle($input['image_id']);
            $result = ['result' => 'processed', 'status' => $image->status, 'attempts' => $image->attempts];
        } elseif ($input['operation'] === 'create_site_release') {
            $release = $site->create($input['content'], $input['label'], User::findOrFail($input['actor_id']));
            $result = ['result' => 'created', 'release_id' => $release->id, 'images' => $release->content['images'] ?? null];
        } elseif ($input['operation'] === 'run_schedule') {
            // The scheduler reads the real clock; fixtures make the schedule due and within its grace window.
            $run = $site->runDueSchedule();
            $result = ['result' => 'schedule', 'outcome' => $run['outcome'] ?? null, 'revision' => $run['publication_revision'] ?? null];
        } elseif ($input['operation'] === 'cancel_schedule') {
            $schedule = $site->cancelSchedule($input['schedule_id'], User::findOrFail($input['actor_id']));
            $result = ['result' => 'cancelled', 'state' => $schedule->state];
        } else {
            $publication = $site->{$input['operation']}(
                $input['release_id'], $input['revision'], User::findOrFail($input['actor_id']), $input['expected_schedule_id'] ?? null
            );
            $result = ['result' => 'published', 'revision' => $publication->revision, 'release_id' => $publication->active_release_id];
        }
    } catch (ValidationException $exception) {
        $result = ['result' => 'rejected', 'errors' => $exception->errors()];
    }
    echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
