<?php

use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionAdministration;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Promotion administration races require test MySQL.');
    }
    $directory = getenv('VASEY_PROMOTION_ADMIN_RACE_DIRECTORY');
    $worker = getenv('VASEY_PROMOTION_ADMIN_RACE_WORKER');
    $mediaRoot = getenv('VASEY_PROMOTION_ADMIN_RACE_MEDIA_ROOT');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true) || ! is_string($mediaRoot) || ! is_dir($mediaRoot)) {
        throw new LogicException('Missing isolated promotion administration race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 16384), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'] ?? null, ['availability', 'price', 'attempt'], true)) {
        throw new LogicException('Unsupported promotion administration race operation.');
    }
    config([
        'commerce.test_pricing_policy' => null, 'commerce.test_promotions' => null,
        'filesystems.disks.local.root' => $mediaRoot, 'filesystems.disks.local.serve' => false,
        'filesystems.disks.local.visibility' => 'private',
    ]);
    Storage::forgetDisk('local');
    Carbon::setTestNow($input['now']);
    CarbonImmutable::setTestNow($input['now']);
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    $wait = function (string $path): void {
        $deadline = microtime(true) + 30;
        do {
            clearstatcache(true, $path);
            if (is_file($path)) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        throw new RuntimeException('Promotion administration worker barrier timed out.');
    };
    $isCampaignLock = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql)
        && str_contains($sql, 'from `promotion_campaigns`') && str_contains($sql, 'for update');
    $ready = false;
    $locked = false;
    DB::connection()->beforeExecuting(function ($query) use ($isCampaignLock, $directory, $worker, $connection, $wait, &$ready): void {
        if (! $ready && $isCampaignLock($query)) {
            $ready = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start-'.$worker);
        }
    });
    DB::listen(function ($query) use ($isCampaignLock, $directory, $worker, $wait, &$locked): void {
        if (! $locked && $isCampaignLock($query->sql)) {
            $locked = true;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
    try {
        if ($input['operation'] === 'availability') {
            $availability = app(PromotionAdministration::class)->setAvailability(
                $input['campaign_id'], $input['revision'], $input['enabled'], User::findOrFail($input['actor_id'])
            );
            $result = ['result' => 'ok', 'revision' => $availability->revision, 'enabled' => $availability->enabled];
        } elseif ($input['operation'] === 'price') {
            $pricing = app(PriceQuote::class)->createWithPromotion($input['quote'], $input['owner'], $input['code']);
            $result = ['result' => 'ok', 'effect_id' => $pricing->id, 'fingerprint' => $pricing->snapshot_hash];
        } else {
            $use = app(PromotionUsage::class)->beginAttempt($input['quote'], $input['owner'], $input['attempt']);
            $result = ['result' => 'ok', 'effect_id' => $use->id, 'attempt_id' => $use->attempt_id];
        }
    } catch (ValidationException $exception) {
        $result = ['result' => 'rejected', 'errors' => $exception->errors()];
    } catch (QuoteException $exception) {
        $result = ['result' => 'rejected', 'code' => $exception->errorCode, 'status' => $exception->status];
    }
    if (! $ready || ! $locked) {
        throw new LogicException('Promotion administration operation missed its campaign barrier.');
    }
    echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
