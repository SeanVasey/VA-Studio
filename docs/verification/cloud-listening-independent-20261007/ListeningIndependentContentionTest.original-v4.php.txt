<?php

namespace Tests\Feature;

use App\Domain\Customers\Listening\SavedListeningLibrary;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class ListeningIndependentContentionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_native_last_version_contender_waits_and_cannot_overwrite_committed_private_list(): void
    {
        $this->assertSame('mysql', DB::getDriverName(), 'This independent proof requires native MySQL; SQLite is insufficient.');
        $customer = CustomerFixtures::account();
        $sync = sys_get_temp_dir().'/va-listening-review-'.bin2hex(random_bytes(12));
        mkdir($sync, 0700);
        $workers = [];
        try {
            $workers['winner'] = new Process([PHP_BINARY, __DIR__.'/listening-worker.php', (string) $customer['user']->id, 'winner', $sync], base_path(), ['APP_KEY' => config('app.key')], null, 15);
            $workers['winner']->start();
            $deadline = microtime(true) + 5;
            while (! file_exists($sync.'/winner-held') && microtime(true) < $deadline && $workers['winner']->isRunning()) {
                usleep(10000);
            }
            $this->assertFileExists($sync.'/winner-held', 'Winner did not hold the real domain transaction: '.$workers['winner']->getErrorOutput().$workers['winner']->getOutput());
            $workers['contender'] = new Process([PHP_BINARY, __DIR__.'/listening-worker.php', (string) $customer['user']->id, 'contender', $sync], base_path(), ['APP_KEY' => config('app.key')], null, 15);
            $workers['contender']->start();
            $waits = 0;
            $deadline = microtime(true) + 5;
            while ($waits === 0 && microtime(true) < $deadline && $workers['contender']->isRunning()) {
                $waits = (int) DB::selectOne('SELECT COUNT(*) AS waits FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_DB = ?', [DB::getDatabaseName()])->waits;
                usleep(10000);
            }
            $this->assertGreaterThan(0, $waits, 'Contender never reached an observed native row-lock wait: '.$workers['contender']->getErrorOutput().$workers['contender']->getOutput());
            file_put_contents($sync.'/release', 'release');
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            }
            $this->assertSame(['status' => 200, 'version' => 1], json_decode(trim($workers['winner']->getOutput()), true, 8, JSON_THROW_ON_ERROR));
            $this->assertSame(['status' => 409], json_decode(trim($workers['contender']->getOutput()), true, 8, JSON_THROW_ON_ERROR));
            $row = SavedListeningLibrary::sole();
            $this->assertSame(1, $row->version);
            $this->assertSame(['Independent winner list'], array_column($row->payload['playlists'], 'name'));
            $this->assertDatabaseCount('customer_saved_tracks', 1);
            file_put_contents(__DIR__.'/native-contention-receipt.json', json_encode([
                'source' => trim(shell_exec('git rev-parse HEAD')),
                'mysql' => DB::selectOne('SELECT VERSION() AS version')->version,
                'isolation' => DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation,
                'observed_waits' => $waits, 'winner' => ['status' => 200, 'version' => 1], 'contender' => ['status' => 409],
                'retained_rows' => 1, 'retained_version' => $row->version,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        } finally {
            file_put_contents($sync.'/release', 'release');
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            foreach (glob($sync.'/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($sync);
        }
    }
}
