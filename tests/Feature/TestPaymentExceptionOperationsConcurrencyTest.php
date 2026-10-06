<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestPaymentFinancialObservation;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures as F;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestPaymentExceptionOperationsConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exception operation claims require independent MySQL sessions; SQLite is not concurrency evidence.');
        }
    }

    public static function scenarios(): array
    {
        return [['competing dispositions'], ['active lease'], ['reclaimed lease']];
    }

    #[DataProvider('scenarios')]
    public function test_independent_operators_serialize_history_and_fence_provider_claims(string $scenario): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $f = PaymentFixtures::started($gateway, true, true);
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $f = F::confirm($f);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $record = OrderFinalization::where('order_id', $f['order']->id)->sole();
        $actor = LicenseFixtures::admin();
        $second = $scenario === 'competing dispositions' ? LicenseFixtures::admin() : $actor;
        $before = F::retained();
        Queue::fake();
        $input = ['public_id' => $record->public_id, 'order_id' => $f['order']->id, 'actor_id' => $actor->id,
            'key' => (string) Str::uuid(), 'media_root' => Storage::disk('local')->path(''), 'at' => now()->toIso8601String(),
            'session' => $gateway->session, 'payment' => $gateway->payment, 'operation' => $scenario === 'competing dispositions' ? 'disposition' : 'reconcile'];
        $inputs = [$input + ['pause' => $scenario === 'competing dispositions' ? 'order' : 'provider'], array_replace($input, ['actor_id' => $second->id])];
        if ($scenario === 'competing dispositions') {
            $inputs[1]['key'] = (string) Str::uuid();
        } elseif ($scenario === 'reclaimed lease') {
            $inputs[1]['at'] = now()->addSeconds(120)->toIso8601String();
        }
        $this->race($inputs, function ($directory, $processes, $connections) use ($scenario, $f): void {
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/locked-0'), $processes);
            touch($directory.'/start-1');
            if ($scenario === 'competing dispositions') {
                $this->observeWait($connections[1], $connections[0], 'orders', $f['order']->id, $processes);
            } else {
                // The second process can finish while the first is inside a provider call: no database locks span I/O.
                $this->await(fn () => is_file($directory.'/finished-1'), $processes);
            }
            touch($directory.'/release-0');
            $results = $this->results($processes, $connections);
            if ($scenario === 'competing dispositions') {
                $this->assertSame(['disposition', 'blocked'], array_column($results, 'result'));
            } elseif ($scenario === 'active lease') {
                $this->assertSame(['reconciliation_observed', 'busy'], array_column($results, 'result'));
                $this->assertSame([], $results[1]['provider_calls']);
            } else {
                $this->assertSame(['stale', 'reconciliation_observed'], array_column($results, 'result'));
            }
            foreach ($results as $result) {
                $this->assertSame([], $result['jobs']);
                foreach ($result['provider_calls'] as $call) {
                    $this->assertSame(0, $call['transaction_level']);
                }
            }
        });
        $this->assertSame($before, F::retained());
        $this->assertSame($scenario === 'competing dispositions' ? 1 : 2, TestPaymentExceptionEvent::count());
        $this->assertSame($scenario === 'competing dispositions' ? 0 : 1, TestPaymentFinancialObservation::count());
        if ($scenario !== 'competing dispositions') {
            $this->assertSame('observed', TestPaymentFinancialObservation::sole()->state);
        }
        Queue::assertNothingPushed();
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/test-exception-operations-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/test-exception-operations-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_EXCEPTION_OPS_DIRECTORY' => $directory, 'VASEY_EXCEPTION_OPS_WORKER' => (string) $worker,
                ], json_encode($input, JSON_THROW_ON_ERROR), 40);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ready = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/ready-'.$worker), true, 16, JSON_THROW_ON_ERROR), [0, 1]);
            $connections = array_column($ready, 'connection_id');
            $this->assertCount(3, array_unique([...$connections, (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id]));
            $this->assertCount(3, array_unique([...array_column($ready, 'pid'), getmypid()]));
            $coordinate($directory, $processes, $connections);
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function results(array $processes, array $connections): array
    {
        $results = [];
        foreach ($processes as $index => $process) {
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), 'Exception operation process failed: '.$process->getOutput().$process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($connections[$index], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);
            $results[] = $result;
        }

        return $results;
    }

    private function observeWait(int $requester, int $blocker, string $table, int $id, array $processes): void
    {
        $this->await(fn () => $this->waiting($requester, $blocker, $table, $id), $processes);
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): bool
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS lock_status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requesting_thread.PROCESSLIST_ID = ? AND blocking_thread.PROCESSLIST_ID = ?
  AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
  AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ?
LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), $table, (string) $id])?->lock_status === 'WAITING';
    }

    private function await(callable $ready, array $processes): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($ready()) {
                return;
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'A exception worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Exception operations did not reach the required exact row wait/barrier.');
    }
}
