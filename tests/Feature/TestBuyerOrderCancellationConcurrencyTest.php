<?php

namespace Tests\Feature;

use App\Domain\Commerce\BuyerCancel\BuyerCancelPolicy;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\TestBuyerOrderCancellation;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\UnpaidRelease\ReleaseTestOrderResources;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

/**
 * Independent MySQL sessions with exact InnoDB lock waits for the buyer's cancellation of a never-sent order: a cancel and
 * a first checkout start (or a second cancel, or a staff never-sent release) serialize on the Order row and exactly one wins.
 * SQLite cannot prove these. Workers: tests/Support/unpaid-release-worker.php.
 */
class TestBuyerOrderCancellationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Buyer cancellation races require independent MySQL sessions and exact InnoDB lock waits.');
        }
    }

    private function setupGateway(): object
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        config(['buyer-cancel.enabled' => true, 'buyer-cancel.policy' => json_encode(BuyerCancelPolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);

        return $gateway;
    }

    public static function winners(): array
    {
        return [['cancel'], ['checkout']];
    }

    /** Inside the attempt window. Whichever takes the Order lock first wins; the other refuses without a provider call. */
    #[DataProvider('winners')]
    public function test_cancel_and_first_checkout_start_serialize_on_the_order_and_exactly_one_wins(string $winner): void
    {
        $gateway = $this->setupGateway();
        $f = CheckoutFixtures::prepared(false, true);
        $original = app(ReadOrder::class)->verify($f['order']);
        $base = $this->input($f['order']->public_id, $gateway) + ['mutex' => 'orders', 'ordinal' => 1];
        $cancel = $base + ['operation' => 'cancel'];
        $start = $base + ['operation' => 'start'];
        $results = $this->race($winner === 'cancel' ? [$cancel, $start] : [$start, $cancel], 'orders', $f['order']->id);
        if ($winner === 'cancel') {
            $this->assertSame(['cancelled', 'CHECKOUT_EXPIRED'], array_column($results, 'result'));
            $this->assertSame([[], []], array_column($results, 'calls'));
            $this->assertDatabaseCount('checkout_intents', 0);
            $this->assertSame('released', InventoryReservation::sole()->state);
            $this->assertSame(1, TestBuyerOrderCancellation::count());
        } else {
            $this->assertSame(['open', 'CHECKOUT_STARTED'], array_column($results, 'result'));
            $this->assertSame(['account', 'create'], array_column($results[0]['calls'], 'operation'));
            $this->assertSame([], $results[1]['calls']);
            $this->assertDatabaseCount('checkout_intents', 1);
            $this->assertSame('pending', InventoryReservation::sole()->state);
            $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
        }
        foreach (['verified_payments', 'order_finalizations', 'license_grants', 'exclusive_sales'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
    }

    public function test_a_second_cancel_waits_and_records_nothing_more(): void
    {
        $gateway = $this->setupGateway();
        $f = CheckoutFixtures::prepared();
        $base = $this->input($f['order']->public_id, $gateway) + ['mutex' => 'orders', 'ordinal' => 1, 'operation' => 'cancel'];
        $results = $this->race([$base, $base], 'orders', $f['order']->id);
        $this->assertSame(['cancelled', 'cancelled'], array_column($results, 'result'));
        $this->assertSame(1, TestBuyerOrderCancellation::count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.order.buyer_cancelled')->count());
    }

    public static function staffWinners(): array
    {
        return [['cancel'], ['staff']];
    }

    /** After the window closes the staff never-sent release may race the buyer: one release of the reservation, one record. */
    #[DataProvider('staffWinners')]
    public function test_cancel_and_staff_never_sent_release_record_exactly_one_release(string $winner): void
    {
        $gateway = $this->setupGateway();
        $f = CheckoutFixtures::prepared();
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $base = $this->input($f['order']->public_id, $gateway) + ['mutex' => 'orders', 'ordinal' => 1];
        $cancel = $base + ['operation' => 'cancel'];
        $staff = $base + ['operation' => 'release', 'actor_id' => LicenseFixtures::admin()->id, 'expected_proof' => ReleaseTestOrderResources::NEVER_SENT];
        $results = $this->race($winner === 'cancel' ? [$cancel, $staff] : [$staff, $cancel], 'orders', $f['order']->id);
        if ($winner === 'cancel') {
            $this->assertSame(['cancelled', 'buyer_cancelled'], array_column($results, 'result'));
            $this->assertSame(1, TestBuyerOrderCancellation::count());
            $this->assertDatabaseCount('test_unsent_order_releases', 0);
        } else {
            $this->assertSame(['released', 'ORDER_NOT_CANCELLABLE'], array_column($results, 'result'));
            $this->assertDatabaseCount('test_buyer_order_cancellations', 0);
            $this->assertDatabaseCount('test_unsent_order_releases', 1);
        }
        $this->assertSame('released', InventoryReservation::sole()->state);
        $this->assertSame([[], []], array_column($results, 'calls'));
    }

    private function input(string $publicId, object $gateway): array
    {
        return ['public_id' => $publicId, 'intent_id' => null, 'media_root' => Storage::disk('local')->path(''),
            'request_id' => (string) Str::uuid(), 'session' => $gateway->session, 'payment' => $gateway->payment, 'now' => now()->toIso8601ZuluString()];
    }

    private function race(array $inputs, string $table, int $id, string $lockIndex = 'PRIMARY', ?string $data = null): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/buyer-cancel-race-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $processes = [];
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_UNPAID_RACE_DIRECTORY' => $directory];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/unpaid-release-worker.php')], base_path(),
                    $environment + ['VASEY_UNPAID_RACE_WORKER' => (string) $index], json_encode($input, JSON_THROW_ON_ERROR), 55);
                $process->start();
                $processes[] = $process;
            }
            $this->await(fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes);
            $ids = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1'), (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id];
            $this->assertCount(3, array_unique($ids));
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/locked-0'), $processes);
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/attempting-1'), $processes);
            $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS status FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?
AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = ?
AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA LIKE ? LIMIT 1
SQL;
            $this->await(fn () => DB::selectOne($sql, [$ids[1], $ids[0], $db['database'], $table, $lockIndex, $data ?? (string) $id])?->status === 'WAITING', $processes,
                fn () => json_encode(DB::select("SELECT OBJECT_NAME, INDEX_NAME, LOCK_TYPE, LOCK_MODE, LOCK_DATA FROM performance_schema.data_locks WHERE LOCK_STATUS = 'WAITING'")));
            $this->assertFileDoesNotExist($directory.'/locked-1');
            touch($directory.'/release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $this->assertJson($process->getOutput(), $process->getOutput().$process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 64, JSON_THROW_ON_ERROR);
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));
            $this->assertSame(array_slice($ids, 0, 2), array_column($results, 'connection'));
            foreach ($results as $result) {
                $this->assertSame(0, $result['transaction_level']);
                foreach ($result['calls'] as $call) {
                    // Only the buyer's checkout start may reach the provider, and never inside a transaction; a cancel never does.
                    $this->assertSame(0, $call['transaction_level']);
                    $this->assertContains($call['operation'], ['account', 'create']);
                }
            }

            return $results;
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $fs->deleteDirectory($directory);
        }
    }

    private function await(callable $predicate, array $processes, ?callable $diagnose = null): void
    {
        $deadline = microtime(true) + 25;
        do {
            clearstatcache();
            if ($predicate()) {
                return;
            }
            foreach ($processes as $process) {
                $process->checkTimeout();
                if (! $process->isRunning()) {
                    $this->fail($process->getOutput().$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Buyer cancellation workers missed the exact lock/barrier state.'.($diagnose ? ' Waiting: '.$diagnose() : ''));
    }
}
