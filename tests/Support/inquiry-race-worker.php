<?php

use App\Domain\Inquiries\InquiryException;
use App\Domain\Inquiries\SubmitInquiry;
use App\Domain\SiteBuilder\SiteContent;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Inquiry races require test MySQL.');
    }
    $directory = getenv('VASEY_INQUIRY_RACE_DIRECTORY');
    $worker = getenv('VASEY_INQUIRY_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing inquiry race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    if (! in_array($input['operation'] ?? null, ['submit', 'withdraw'], true)) {
        throw new LogicException('Unsupported inquiry race operation.');
    }
    config(['inquiries.enabled' => true, 'inquiries.privacy_notice' => 'SYNTHETIC RACE PRIVACY NOTICE',
        'inquiries.retention_policy_reference' => 'SYNTHETIC-RACE-RETENTION', 'inquiries.operator_user_id' => $input['operator_id']]);
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
        throw new RuntimeException('Inquiry worker barrier timed out.');
    };
    $isLock = fn (string $sql): bool => preg_match('/\Aselect\b/i', $sql)
        && str_contains($sql, 'from `site_publications`') && str_contains($sql, 'for update');
    $armed = false;
    DB::connection()->beforeExecuting(function ($query) use ($isLock, $directory, $worker, $connection, $wait, &$armed): void {
        if ($isLock($query)) {
            $armed = true;
            file_put_contents($directory.'/ready-'.$worker, (string) $connection);
            $wait($directory.'/start', $directory.'/start-'.$worker);
        }
    });
    DB::listen(function ($query) use ($isLock, $directory, $worker, $wait, &$armed): void {
        if ($armed && $isLock($query->sql)) {
            $armed = false;
            touch($directory.'/locked-'.$worker);
            $wait($directory.'/commit');
        }
    });
    try {
        if ($input['operation'] === 'submit') {
            $result = app(SubmitInquiry::class)->handle($input['payload'], $input['owner_hash']);
        } else {
            $publication = app(SiteContent::class)->publish($input['release_id'], $input['revision'], User::findOrFail($input['operator_id']));
            $result = ['result' => 'published', 'revision' => $publication->revision, 'release_id' => $publication->active_release_id];
        }
    } catch (InquiryException $error) {
        $result = ['result' => 'rejected', 'status' => $error->status];
    }
    echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
