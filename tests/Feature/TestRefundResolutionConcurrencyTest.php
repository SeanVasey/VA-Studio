<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\RefundResolutionFixtures as F;
use Tests\TestCase;

class TestRefundResolutionConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Refund resolution races require independent MySQL sessions and exact InnoDB record waits.');
        }
    }

    private function gateway(): object
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

    public static function commands(): array
    {
        return ['same request' => [true], 'competing request' => [false]];
    }

    #[DataProvider('commands')]
    public function test_exact_authority_and_order_fences_keep_one_resolution_for_identical_or_competing_commands(bool $same): void
    {
        $gateway = $this->gateway();
        $f = F::exception($gateway);
        $original = app(ReadOrder::class)->verify($f['order']);
        $table = $same ? 'users' : 'orders';
        $input = $this->input($f, $gateway) + ['operation' => 'resolve', 'mutex' => $table];
        // Identical requests require the same actor and serialize at User first. Different
        // authorized actors reach the shared Order fence independently.
        $second = array_replace($input, ['ordinal' => 1, 'request_id' => $same ? $input['request_id'] : (string) Str::uuid(),
            'actor_id' => $same ? $f['admin']->id : LicenseFixtures::admin()->id]);
        $results = $this->race([$input + ['ordinal' => $same ? 7 : 5], $second], $table, $same ? $f['admin']->id : $f['order']->id);
        $this->assertSame(['released', $same ? 'released' : 'refused'], array_column($results, 'result'));
        $this->assertSame(1, TestRefundResolution::count());
        $this->assertDatabaseCount('test_payment_exception_events', 2);
        $this->assertDatabaseCount('test_refund_resolution_requests', 1);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('exclusive_sales', 0);
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        $this->assertSame([], $results[1]['calls']);
    }

    public function test_committed_actor_withdrawal_fences_the_resolution_request_before_provider_io(): void
    {
        $gateway = $this->gateway();
        $f = F::exception($gateway);
        $original = app(ReadOrder::class)->verify($f['order']);
        $input = $this->input($f, $gateway) + ['mutex' => 'users', 'ordinal' => 1];
        $results = $this->race([$input + ['operation' => 'withdraw'], $input + ['operation' => 'resolve']], 'users', $f['admin']->id);
        $this->assertSame(['withdrawn', 'refused'], array_column($results, 'result'));
        $this->assertSame([], $results[1]['calls']);
        $this->assertDatabaseCount('test_refund_resolution_requests', 0);
        $this->assertDatabaseCount('test_refund_resolutions', 0);
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
        $this->assertFalse(User::findOrFail($f['admin']->id)->is_admin);
    }

    public function test_new_scope_capacity_waits_for_the_refund_release_commit(): void
    {
        $gateway = $this->gateway();
        $f = PaymentFixtures::started($gateway, false, false);
        $this->travelTo($f['order']->attempt()->sole()->expires_at->addSecond());
        $f = FinalizationFixtures::confirm($f);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $f += ['record' => OrderFinalization::where('order_id', $f['order']->id)->sole(), 'admin' => LicenseFixtures::admin()];
        $other = InventoryFixtures::selection($f['scope'])['quote'];
        $input = $this->input($f, $gateway) + ['mutex' => 'rights_scopes', 'ordinal' => 1];
        $results = $this->race([$input + ['operation' => 'resolve'], $input + ['operation' => 'hold', 'quote_id' => $other->public_id]], 'rights_scopes', $f['scope']->id);
        $this->assertSame(['released', 'held'], array_column($results, 'result'));
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $f['order']->quote_id, 'state' => 'released']);
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $other->id, 'state' => 'held']);
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('exclusive_sales', 0);
        $this->assertDatabaseCount('verified_payments', 1);
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($f['order']));
    }

    public function test_last_promotion_capacity_waits_for_the_same_atomic_refund_release(): void
    {
        $gateway = $this->gateway();
        $f = InventoryFixtures::selection();
        $promotion = PromotionFixtures::policy(['max_uses' => 1,
            'eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [$f['revision']->id]]]);
        PromotionFixtures::configure([$promotion]);
        app(PriceQuote::class)->createWithPromotion($f['quote']->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($f['quote']));
        app(HostedCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $gateway->session = array_replace($gateway->session, ['status' => 'complete', 'payment_status' => 'paid', 'url' => null, 'payment_intent' => PaymentFixtures::PAYMENT]);
        $gateway->payment = PaymentFixtures::payment($gateway->session);
        $this->travelTo($order->attempt()->sole()->expires_at->addSecond());
        $f = FinalizationFixtures::confirm($f + ['order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole()]);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));
        $f += ['record' => OrderFinalization::where('order_id', $order->id)->sole(), 'admin' => LicenseFixtures::admin()];
        $other = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        $campaignId = PromotionUse::sole()->promotion_campaign_id;
        $input = $this->input($f, $gateway) + ['mutex' => 'promotion_campaigns', 'ordinal' => 1, 'promotion' => $promotion];
        $results = $this->race([$input + ['operation' => 'resolve'], $input + ['operation' => 'price', 'quote_id' => $other->public_id]], 'promotion_campaigns', $campaignId);
        $this->assertSame(['released', 'priced'], array_column($results, 'result'));
        $this->assertSame(['released', 'held'], PromotionUse::orderBy('id')->pluck('state')->all());
        $this->assertSame(1, PromotionUse::whereIn('state', ['held', 'pending', 'consumed'])->count());
        $this->assertSame($f['original'], app(ReadOrder::class)->verify($order));
        $this->assertDatabaseCount('license_grants', 0);
        $this->assertDatabaseCount('exclusive_sales', 0);
    }

    private function input(array $f, object $gateway): array
    {
        return ['public_id' => $f['record']->public_id, 'actor_id' => $f['admin']->id,
            'media_root' => Storage::disk('local')->path(''), 'request_id' => (string) Str::uuid(),
            'session' => $gateway->session, 'payment' => $gateway->payment, 'now' => now()->toIso8601ZuluString()];
    }

    private function race(array $inputs, string $table, int $id): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/refund-race-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $processes = [];
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_REFUND_RACE_DIRECTORY' => $directory];
        try {
            foreach ($inputs as $index => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/refund-resolution-worker.php')], base_path(),
                    $environment + ['VASEY_REFUND_RACE_WORKER' => (string) $index], json_encode($input, JSON_THROW_ON_ERROR), 55);
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
AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' AND requested.LOCK_DATA = ? LIMIT 1
SQL;
            $this->await(fn () => DB::selectOne($sql, [$ids[1], $ids[0], $db['database'], $table, (string) $id])?->status === 'WAITING', $processes);
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
                    $this->assertSame(0, $call['transaction_level']);
                    $this->assertContains($call['operation'], ['account', 'retrieve', 'payment_intent', 'financial_state']);
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

    private function await(callable $predicate, array $processes): void
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
        $this->fail('Refund workers missed the exact lock/barrier state.');
    }
}
