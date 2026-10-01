<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || DB::getDriverName() !== 'mysql') {
        throw new LogicException('Inquiry races require test MySQL.');
    }
    $directory = getenv('VASEY_INQUIRY_NOTIFICATION_RACE_DIRECTORY');
    $worker = getenv('VASEY_INQUIRY_NOTIFICATION_RACE_WORKER');
    if (! is_string($directory) || ! is_dir($directory) || ! in_array($worker, ['0', '1'], true)) {
        throw new LogicException('Missing inquiry race configuration.');
    }
    $input = json_decode(stream_get_contents(STDIN, 65536), true, 16, JSON_THROW_ON_ERROR);
    if (! is_int($input['intent_id'] ?? null) || $input['intent_id'] < 1) {
        throw new LogicException('Unsupported inquiry notification race locator.');
    }
    config(['inquiries.operator_notifications_enabled' => true]);
    app()->instance(\App\Domain\Inquiries\Notifications\InquiryAlertTransport::class,
        new class($directory) implements \App\Domain\Inquiries\Notifications\InquiryAlertTransport
        {
            public function __construct(private string $directory)
            {
            }

            public function submit(\App\Domain\Inquiries\Notifications\OperatorInquiryAlert $alert): void
            {
                if (DB::transactionLevel() !== 0) {
                    throw new LogicException('Synthetic handoff received an uncommitted claim.');
                }
                $path = $this->directory.'/handoffs.jsonl';
                $bytes = json_encode(['operator_id' => $alert->operatorId, 'receipt' => $alert->receipt], JSON_THROW_ON_ERROR)."\n";
                if (file_put_contents($path, $bytes, FILE_APPEND | LOCK_EX) !== strlen($bytes) || ! chmod($path, 0600)) {
                    throw new RuntimeException('Synthetic handoff recording failed.');
                }
            }
        });
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
        && str_contains($sql, 'from `inquiry_notification_intents`') && str_contains($sql, 'for update');
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
    $state = app(\App\Domain\Inquiries\Notifications\InquiryNotificationWork::class)->process($input['intent_id']);
    $result = ['state' => $state];
    echo json_encode($result + ['connection_id' => $connection, 'pid' => getmypid(), 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR);
    exit(0);
} catch (Throwable $error) {
    echo json_encode(['result' => 'worker_failed', 'exception' => $error::class], JSON_THROW_ON_ERROR);
    exit(1);
}
