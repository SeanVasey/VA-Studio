<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\TestUnsentOrderRelease;
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
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

/** Independent MySQL sessions with exact InnoDB lock waits for the never-sent release. SQLite cannot prove these. */
class TestUnsentOrderReleaseConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Never-sent release races require independent MySQL sessions and exact InnoDB lock waits.');
        }
    }

    private function setupGateway(): object
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);

        return $gateway;
    }

    public static function winners(): array
    {
        return [['release'], ['checkout']];
    }

    /**
     * The buyer's first checkout start and a staff release both take the Order fence. The start worker's clock is set
     * before the window closes, so only the retained release (not the clock) can stop it after the release wins.
     */
    #[DataProvider('winners')]
    public function test_first_checkout_start_and_never_sent_release_serialize_on_the_order_fence(string $winner): void
    {
        $gateway = $this->setupGateway();
        $f = CheckoutFixtures::prepared(false, true);
        $expires = $f['order']->attempt()->sole()->expires_at;
        $admin = LicenseFixtures::admin();
        $original = app(ReadOrder::class)->verify($f['order']);
        $this->travelTo($expires->addSecond());
        $base = $this->input($f['order']->public_id, $gateway) + ['mutex' => 'orders', 'ordinal' => 1];
        $release = $base + ['operation' => 'release', 'actor_id' => $admin->id, 'expected_proof' => ReleaseTestOrderResources::NEVER_SENT];
        $start = ['operation' => 'start', 'now' => $expires->subSeconds(5)->toIso8601ZuluString()] + $base;
        $results = $this->race($winner === 'release' ? [$release, $start] : [$start, $release], 'orders', $f['order']->id);
        if ($winner === 'release') {
            $this->assertSame(['released', 'CHECKOUT_EXPIRED'], array_column($results, 'result'));
            $this->assertSame([[], []], array_column($results, 'calls'));
            $this->assertDatabaseCount('checkout_intents', 0);
            $this->assertSame('released', InventoryReservation::sole()->state);
            $this->assertSame(1, TestUnsentOrderRelease::count());
        } else {
            $this->assertSame(['open', 'stale'], array_column($results, 'result'));
            $this->assertSame(['account', 'create'], array_column($results[0]['calls'], 'operation'));
            $this->assertSame([], $results[1]['calls']);
            $this->assertDatabaseCount('checkout_intents', 1);
            $this->assertSame('pending', InventoryReservation::sole()->state);
            $this->assertDatabaseCount('test_unsent_order_releases', 0);
        }
        foreach (['verified_payments', 'order_finalizations', 'license_grants', 'exclusive_sales'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
    }

    public function test_second_staff_release_waits_and_cannot_record_a_second_release(): void
    {
        $gateway = $this->setupGateway();
        $f = CheckoutFixtures::prepared();
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $base = $this->input($f['order']->public_id, $gateway) + ['mutex' => 'orders', 'ordinal' => 1, 'operation' => 'release'];
        $first = ['actor_id' => LicenseFixtures::admin()->id] + $base;
        $second = ['actor_id' => LicenseFixtures::admin()->id, 'request_id' => (string) Str::uuid()] + $base;
        // Both staff requests lock the order by its public identifier, so the second waits on that unique index record.
        $results = $this->race([$first, $second], 'orders', $f['order']->id, 'orders_public_id_unique', "'".$f['order']->public_id."'%");
        $this->assertSame(['released', 'stale'], array_column($results, 'result'));
        $this->assertSame($first['actor_id'], TestUnsentOrderRelease::sole()->actor_id);
        $this->assertDatabaseCount('test_unpaid_release_events', 2);
    }

    /** A new hold on the shared rights scope waits for the release commit, then reserves the freed scope. */
    public function test_new_hold_on_the_shared_scope_waits_for_the_release_commit(): void
    {
        $gateway = $this->setupGateway();
        $f = CheckoutFixtures::prepared();
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $other = InventoryFixtures::selection($f['scope'])['quote'];
        $base = $this->input($f['order']->public_id, $gateway) + ['mutex' => 'rights_scopes', 'ordinal' => 1];
        $results = $this->race([$base + ['operation' => 'release', 'actor_id' => LicenseFixtures::admin()->id],
            $base + ['operation' => 'hold', 'quote_id' => $other->public_id]], 'rights_scopes', $f['scope']->id);
        $this->assertSame(['released', 'held'], array_column($results, 'result'));
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $f['order']->quote_id, 'state' => 'released']);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $other->id, 'state' => 'held']);
    }

    private function input(string $publicId, object $gateway): array
    {
        return ['public_id' => $publicId, 'intent_id' => null, 'media_root' => Storage::disk('local')->path(''),
            'request_id' => (string) Str::uuid(), 'session' => $gateway->session, 'payment' => $gateway->payment, 'now' => now()->toIso8601ZuluString()];
    }

    private function race(array $inputs, string $table, int $id, string $lockIndex = 'PRIMARY', ?string $data = null): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/unsent-race-'.Str::uuid());
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
                    // Only the buyer's checkout start may reach the provider, and never inside a transaction.
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
        $this->fail('Never-sent release workers missed the exact lock/barrier state.'.($diagnose ? ' Waiting: '.$diagnose() : ''));
    }
}
