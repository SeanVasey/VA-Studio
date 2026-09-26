<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Independent MySQL workers; the first retains its real mutex until the second attempts it. */
final class FinalizationRace
{
    public static function run(TestCase $test, array $inputs): array
    {
        $test->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/finalization-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/finalization-race-worker.php')], base_path(),
                    self::environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 55);
                $process->start(); $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'startup');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'),
                (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id];
            $test->assertCount(3, array_unique($ids));
            touch($directory.'/start-0');
            self::until($test, $processes, fn () => is_file($directory.'/locked-0'), 'first acquired mutex');
            touch($directory.'/start-1');
            self::until($test, $processes, fn () => is_file($directory.'/attempting-1'), 'second mutex attempt');
            // Both workers have reached the same physical row lock; worker zero still owns it.
            $test->assertTrue($processes[0]->isRunning()); $test->assertTrue($processes[1]->isRunning());
            $test->assertFileDoesNotExist($directory.'/locked-1');
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait(); $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            foreach ($results as $result) { $test->assertSame(0, $result['transaction_level']); $test->assertSame([], $result['provider_calls']); }

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
                if (! $process->isRunning()) { $test->fail('Finalization worker exited before '.$stage.': '.$process->getOutput().$process->getErrorOutput()); }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Finalization workers missed '.$stage.' barrier.');
    }

    private static function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_FINALIZATION_RACE_DIRECTORY' => $directory, 'VASEY_FINALIZATION_RACE_WORKER' => (string) $worker,
            'VASEY_FINALIZATION_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
