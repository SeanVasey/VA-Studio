<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\StripeWebhookReceipt;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\StripeWebhookFixtures as Fixtures;
use Tests\TestCase;

/** Independent processes race the first insert; the parent reads only committed evidence. */
class StripeWebhookConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent-process Stripe receipt races require MySQL.');
        }
    }

    public function test_simultaneous_equivalent_deliveries_acknowledge_one_committed_receipt(): void
    {
        $event = Fixtures::event();
        $first = Fixtures::body($event);
        $event['pending_webhooks'] = 0;
        $second = json_encode(array_reverse($event, true), JSON_THROW_ON_ERROR);
        $results = $this->race([$first, $second]);
        $this->assertSame(['accepted', 'accepted'], array_column($results, 'result'));
        $this->assertSame($results[0]['receipt_id'], $results[1]['receipt_id']);
        $this->assertSame($results[0]['fingerprint'], $results[1]['fingerprint']);
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertContains(Crypt::decryptString(StripeWebhookReceipt::query()->sole()->payload_ciphertext), [$first, $second]);
    }

    public function test_simultaneous_conflicting_deliveries_retain_one_winner_and_reject_the_conflict(): void
    {
        $event = Fixtures::event();
        $first = Fixtures::body($event);
        $event['data']['object']['amount_total']++;
        $bodies = [$first, Fixtures::body($event)];
        $results = $this->race($bodies);
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['accepted', 'rejected'], $outcomes);
        $winner = $results[0]['result'] === 'accepted' ? 0 : 1;
        $this->assertSame('STRIPE_EVENT_CONFLICT', $results[1 - $winner]['error_code']);
        $this->assertSame(409, $results[1 - $winner]['status']);
        $this->assertDatabaseCount('stripe_webhook_receipts', 1);
        $this->assertSame($bodies[$winner], Crypt::decryptString(StripeWebhookReceipt::query()->sole()->payload_ciphertext));
    }

    private function race(array $bodies): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/stripe-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        try {
            foreach ($bodies as $worker => $body) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/stripe-webhook-race-worker.php')], base_path(), $this->workerEnvironment($directory, $worker));
                $process->setInput($body)->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }
            $readyPaths = [$directory.'/ready-0', $directory.'/ready-1'];
            $deadline = microtime(true) + 20;
            do {
                clearstatcache();
                if (count(array_filter($readyPaths, 'is_file')) === 2) {
                    break;
                }
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Webhook worker exited before barrier: '.$process->getOutput());
                    }
                    $process->checkTimeout();
                }
                usleep(10000);
            } while (microtime(true) < $deadline);
            $ids = [(int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id];
            foreach ($readyPaths as $path) {
                $this->assertFileExists($path, 'Webhook worker did not reach its insert barrier.');
                $ids[] = (int) file_get_contents($path);
            }
            $this->assertCount(3, array_unique($ids), 'Parent and workers must use independent MySQL connections.');
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), 'Webhook worker failed: '.$process->getOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function workerEnvironment(string $directory, int $worker): array
    {
        $db = DB::connection()->getConfig();

        // Test-only connection/key material goes through child environment, never arguments/logs.
        return [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'],
            'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'DB_CHARSET' => (string) $db['charset'],
            'DB_COLLATION' => (string) $db['collation'], 'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VASEY_STRIPE_RACE_DIRECTORY' => $directory, 'VASEY_STRIPE_RACE_WORKER' => (string) $worker,
        ];
    }
}
