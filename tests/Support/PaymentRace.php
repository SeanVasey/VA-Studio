<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Two independent MySQL workers coordinated outside all provider/database transactions. */
final class PaymentRace
{
    public static function run(TestCase $test, array $inputs, string $scenario): array
    {
        $test->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/payment-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/payment-race-worker.php')], base_path(),
                    self::environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 55);
                $process->start(); $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'worker startup');
            $connectionIds = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'),
                (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id];
            $test->assertCount(3, array_unique($connectionIds));
            touch($directory.'/start-0');
            if (in_array($scenario, ['stale_lease', 'stale_failure'], true)) {
                self::until($test, $processes, fn () => is_file($directory.'/provider-0'), 'first claimed receipt');
            }
            touch($directory.'/start-1');
            if ($scenario === 'same_receipt') {
                self::until($test, $processes, function () use ($directory, $processes): bool {
                    $waiting = (int) is_file($directory.'/provider-0') + (int) is_file($directory.'/provider-1');
                    $finished = count(array_filter($processes, fn ($process) => ! $process->isRunning()));

                    return $waiting === 1 && $finished === 1;
                }, 'one claim winner and one busy worker');
                touch($directory.'/release-0'); touch($directory.'/release-1');
            } else {
                self::until($test, $processes, fn () => is_file($directory.'/provider-0') && is_file($directory.'/provider-1'), 'both provider observations');
                if (in_array($scenario, ['stale_lease', 'stale_failure', 'stale_observation'], true)) {
                    touch($directory.'/release-1'); $processes[1]->wait();
                    $test->assertSame(0, $processes[1]->getExitCode(), $processes[1]->getOutput());
                    touch($directory.'/release-0');
                } else { touch($directory.'/release-0'); touch($directory.'/release-1'); }
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait(); $test->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

            return $results;
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            $filesystem->deleteDirectory($directory);
        }
    }

    private static function until(TestCase $test, array $processes, callable $predicate, string $stage): void
    {
        $deadline = microtime(true) + 25;
        do {
            clearstatcache(); if ($predicate()) { return; }
            foreach ($processes as $process) {
                $process->checkTimeout();
                if (! $process->isRunning() && $process->getExitCode() !== 0) { $test->fail('Payment worker failed: '.$process->getOutput()); }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Payment workers missed '.$stage.' barrier.');
    }

    private static function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_PAYMENT_RACE_DIRECTORY' => $directory, 'VASEY_PAYMENT_RACE_WORKER' => (string) $worker,
            'VASEY_PAYMENT_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
