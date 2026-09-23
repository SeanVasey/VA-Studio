<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class InventoryRace
{
    public static function run(TestCase $test, array $inputs, ?callable $beforeRelease = null): array
    {
        $test->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/inventory-race-'.Str::uuid());
        $filesystem = new Filesystem; $filesystem->makeDirectory($directory, 0700, true); $processes = [];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/inventory-race-worker.php')], base_path(),
                    self::environment($directory, $index), json_encode($input, JSON_THROW_ON_ERROR), 35);
                $process->start(); $processes[] = $process;
            }
            $paths = [$directory.'/ready-0', $directory.'/ready-1']; $deadline = microtime(true) + 20;
            do {
                clearstatcache();
                if (count(array_filter($paths, 'is_file')) === 2) { break; }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) { $test->fail('Inventory worker exited: '.$process->getOutput()); }
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            foreach ($paths as $path) { $test->assertFileExists($path, 'Worker missed the shared lock barrier.'); }
            $connections = array_map(fn ($path) => (int) file_get_contents($path), $paths);
            $connections[] = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $test->assertCount(3, array_unique($connections));
            if ($beforeRelease !== null) { $beforeRelease(); }
            touch($directory.'/release'); $results = [];
            foreach ($processes as $process) {
                $process->wait(); $test->assertSame(0, $process->getExitCode(), $process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
            }
            $test->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            return $results;
        } finally {
            foreach ($processes as $process) { if ($process->isRunning()) { $process->stop(1); } }
            $filesystem->deleteDirectory($directory);
        }
    }

    private static function environment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_INVENTORY_RACE_DIRECTORY' => $directory, 'VASEY_INVENTORY_RACE_WORKER' => (string) $worker,
            'VASEY_INVENTORY_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')];
    }
}
