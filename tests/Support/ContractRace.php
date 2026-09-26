<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Independent processes prove durable claim fencing; shared fixtures contain synthetic identities only. */
final class ContractRace
{
    public static function run(TestCase $test, array $inputs, string $scenario): array
    {
        $test->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/contract-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/contract-race-worker.php')], base_path(),
                    self::environment($directory, $index), json_encode($input + ['scenario' => $scenario], JSON_THROW_ON_ERROR), 55);
                $process->start(); $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'startup');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'),
                (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id];
            $test->assertCount(3, array_unique($ids));
            touch($directory.'/start-0');
            if ($scenario === 'duplicate_request') {
                touch($directory.'/start-1');
                self::until($test, $processes, fn () => is_file($directory.'/request-0') && is_file($directory.'/request-1'), 'request mutex');
                touch($directory.'/release-0'); touch($directory.'/release-1');
            } else {
                self::until($test, $processes, fn () => is_file($directory.'/render-0'), 'first claimed renderer');
                touch($directory.'/start-1');
                if ($scenario === 'same_request') {
                    self::until($test, $processes, fn () => ! $processes[1]->isRunning(), 'second worker busy result');
                    touch($directory.'/release-0');
                } else {
                    self::until($test, $processes, fn () => is_file($directory.'/render-1'), 'successor claim');
                    touch($directory.'/release-1'); $processes[1]->wait();
                    $test->assertSame(0, $processes[1]->getExitCode(), $processes[1]->getOutput().$processes[1]->getErrorOutput());
                    touch($directory.'/release-0');
                }
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait(); $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            foreach ($results as $result) {
                $test->assertSame(0, $result['transaction_level']); $test->assertSame([], $result['provider_calls']);
                foreach ($result['render_transaction_levels'] as $level) { $test->assertSame(0, $level); }
            }

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
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $test->fail('Contract worker failed at '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Contract workers missed '.$stage.' barrier.');
    }

    private static function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_CONTRACT_RACE_DIRECTORY' => $directory, 'VASEY_CONTRACT_RACE_WORKER' => (string) $worker,
            'VASEY_CONTRACT_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
