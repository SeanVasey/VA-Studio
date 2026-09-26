<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Independent MySQL connections and real sealed spools meet outside transactions before competing for access. */
final class DeliveryRace
{
    public static function run(TestCase $test, array $input): array
    {
        $test->assertSame('mysql', DB::getDriverName()); $test->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/delivery-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        try {
            foreach ([0, 1] as $index) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/delivery-race-worker.php')], base_path(),
                    self::environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 55);
                $process->start(); $processes[] = $process;
            }
            self::until($test, $processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'startup');
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'),
                (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id];
            $test->assertCount(3, array_unique($ids)); touch($directory.'/start');
            if ($input['mode'] === 'block_redemption') {
                self::until($test, $processes, fn () => is_file($directory.'/verified-0') && is_file($directory.'/blocked-1'), 'block committed after redemption preflight');
                $test->assertSame('2', file_get_contents($directory.'/blocked-1'));
                $test->assertSame(0, DB::table('test_delivery_redemptions')->count());
                $test->assertSame(1, (int) DB::table('test_delivery_controls')->where('order_id', $input['order_id'])->value('blocked'));
            } else {
                self::until($test, $processes, fn () => is_file($directory.'/verified-0') && is_file($directory.'/verified-1'), 'independent sealed-byte preflight');
            }
            touch($directory.'/release'); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $test->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            foreach ($results as $index => $result) {
                $test->assertSame(0, $result['transaction_level']); $test->assertSame([], $result['provider_calls']);
                $test->assertSame([], $result['render_calls']);
                $test->assertSame($input['mode'] === 'block_redemption' && $index === 1 ? [] : [0], $result['verification_transaction_levels']);
                $test->assertSame(0, $result['open_prepared_resources']);
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
                    $test->fail('Delivery worker failed at '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $test->fail('Delivery workers missed '.$stage.' barrier.');
    }

    private static function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();
        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_DELIVERY_RACE_DIRECTORY' => $directory, 'VASEY_DELIVERY_RACE_WORKER' => (string) $worker,
            'VASEY_DELIVERY_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
