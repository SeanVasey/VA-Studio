<?php

namespace Tests\Feature;

use App\Domain\Catalog\DiscoverySitemap\SitemapSchema;
use App\Domain\Catalog\DiscoverySitemap\SitemapStore;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class DiscoverySitemapConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_native_same_ordinal_retry_and_different_completion_pointer_cas_have_observed_contention(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Observed contention requires the dedicated native MySQL fixture database.');
        }
        config(['app.key' => 'base64:'.base64_encode(str_repeat('s', 32)), 'app.url' => 'https://synthetic.example', 'discovery-sitemap.enabled' => true]);
        $store = app(SitemapStore::class);
        $first = $store->newRequest();
        $firstId = $store->start($first)['generation'];
        $steps = $this->contend('step', [$first, $first]);
        foreach ($steps['results'] as $result) {
            $this->assertSame('complete', $result['ok']['state']);
        }
        $this->assertSame(2, $steps['observed_waiters']);
        $this->assertSame(1, DB::table('discovery_sitemap_windows')->count());
        $second = $store->newRequest();
        $secondId = $store->start($second)['generation'];
        $store->step($second, 1);
        $pointers = $this->contend('publish', [$first, $second]);
        $this->assertSame(2, $pointers['observed_waiters']);
        $this->assertCount(1, array_filter($pointers['results'], fn ($result) => isset($result['ok'])));
        $this->assertCount(1, array_filter($pointers['results'], fn ($result) => ($result['refused'] ?? null) === 'pointer_conflict'));
        $winner = DB::table('discovery_sitemap_current')->value('generation_id');
        $this->assertContains($winner, [$firstId, $secondId]);
        $this->assertSame(1, (int) DB::table('discovery_sitemap_current')->value('revision'));
        $this->assertSame(2, DB::table('discovery_sitemap_windows')->count());
        $this->assertSame(129, substr_count($store->currentIndexXml(), '<sitemap>'));
    }

    private function contend(string $operation, array $requests): array
    {
        $config = [];
        foreach (['app.key', 'app.url', 'media', 'commerce', 'filesystems', 'discovery-sitemap.enabled'] as $key) {
            $config[$key] = config($key);
        }
        $processes = [];
        $files = [];
        $pdo = DB::connection()->getPdo();
        $waiters = 0;
        try {
            DB::beginTransaction();
            $pdo->query('SELECT * FROM '.(new SitemapSchema)->table(SitemapSchema::TABLES[2]).' WHERE id = 1 FOR UPDATE')->fetchAll();
            foreach ($requests as $request) {
                $path = tempnam(sys_get_temp_dir(), 'dsm-race-');
                chmod($path, 0600);
                file_put_contents($path, json_encode(['operation' => $operation, 'request' => $request, 'configuration' => $config], JSON_THROW_ON_ERROR));
                $files[] = $path;
                $process = new Process([PHP_BINARY, base_path('tests/Support/discovery-sitemap-native-worker.php'), $path], base_path(), null, null, 25);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            $ids = [];
            while (microtime(true) < $deadline) {
                $ids = [];
                foreach ($processes as $process) {
                    if (preg_match('/"ready":([0-9]+)/', $process->getOutput(), $match)) {
                        $ids[] = (int) $match[1];
                    }
                }
                if (count($ids) === 2) {
                    $s = $pdo->prepare('SELECT COUNT(DISTINCT w.REQUESTING_THREAD_ID) FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID IN (?, ?)');
                    $s->execute($ids);
                    $waiters = (int) $s->fetchColumn();
                    if ($waiters === 2) {
                        break;
                    }
                }
                usleep(20000);
            }
            $this->assertSame(2, $waiters, 'Both independent connections must be observed waiting on the held pointer row.');
            DB::rollBack();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $lines = array_values(array_filter(explode("\n", $process->getOutput())));
                $results[] = json_decode(end($lines), true, 8, JSON_THROW_ON_ERROR);
            }

            return ['observed_waiters' => $waiters, 'results' => $results];
        } finally {
            if (DB::transactionLevel() !== 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(0);
                }
            }
            foreach ($files as $path) {
                unlink($path);
            }
        }
    }
}
