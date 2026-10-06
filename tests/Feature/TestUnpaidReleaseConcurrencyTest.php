<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\UnpaidReleaseFixtures as F;
use Tests\TestCase;

class TestUnpaidReleaseConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Unpaid release races require independent MySQL sessions and exact InnoDB lock waits.');
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
        return [['payment'], ['release']];
    }

    #[DataProvider('winners')]
    public function test_exact_order_fence_preserves_money_and_the_winning_resource_disposition(string $winner): void
    {
        $gateway = $this->setupGateway();
        $f = F::started($gateway);
        $original = app(ReadOrder::class)->verify($f['order']);
        $input = $this->input($f, $gateway) + ['mutex' => 'orders'];
        $release = $input + ['operation' => 'release', 'ordinal' => 2];
        $payment = $input + ['operation' => 'payment', 'ordinal' => 1];
        // A release that follows payment stops at its claim's first Order fence.
        if ($winner === 'payment') {
            $release['ordinal'] = 1;
        }
        $results = $this->race($winner === 'payment' ? [$payment, $release] : [$release, $payment], 'orders', $f['order']->id);
        $this->assertSame($winner === 'payment' ? ['awaiting_finalization', 'payment_recorded'] : ['released', 'awaiting_finalization'], array_column($results, 'result'));
        $payment = VerifiedPayment::sole();
        $this->assertTrue($payment->confirmed_at->lessThan($f['order']->attempt()->sole()->expires_at));
        $this->assertSame($winner === 'payment' ? 'paid' : 'paid_exception', app(FinalizeTestPayment::class)->handle($payment->id));
        $this->assertSame($winner === 'payment' ? null : 'released_attempt', OrderFinalization::sole()->reason);
        $this->assertSame($winner === 'payment' ? 'consumed' : 'released', InventoryReservation::sole()->state);
        $this->assertSame($winner === 'payment' ? 'consumed' : 'released', PromotionUse::sole()->state);
        $this->assertDatabaseCount('test_unpaid_releases', $winner === 'payment' ? 0 : 1);
        foreach (['license_grants', 'pending_entitlements', 'exclusive_sales'] as $table) {
            $this->assertDatabaseCount($table, $winner === 'payment' ? 1 : 0);
        }
        $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
    }

    public static function resources(): array
    {
        return [['shared_scope'], ['last_promotion_use']];
    }

    #[DataProvider('resources')]
    public function test_release_serializes_new_capacity_users_without_reviving_old_bindings(string $resource): void
    {
        $gateway = $this->setupGateway();
        $f = InventoryFixtures::selection();
        $quote = $f['quote'];
        // Legacy shared-scope exercises permit advisory reads while the authoritative
        // reservation/campaign writer waits. Activated-exclusive refusal is tested separately.
        $other = $resource === 'shared_scope'
            ? InventoryFixtures::selection($f['scope'])['quote']
            : app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        if ($resource === 'last_promotion_use') {
            $promotion = PromotionFixtures::policy(['max_uses' => 1, 'eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => [$f['revision']->id]]]);
            PromotionFixtures::configure([$promotion]);
            app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
            $f['promotion'] = $promotion;
        } else {
            app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        }
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
        app(HostedCheckout::class)->start($order->public_id, InventoryFixtures::OWNER);
        $gateway->session = array_replace($gateway->session, ['status' => 'expired', 'url' => null, 'after_expiration' => null, 'recovered_from' => null]);
        $f += ['order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole(), 'admin' => LicenseFixtures::admin()];
        $mutex = $resource === 'last_promotion_use' ? 'promotion_campaigns' : 'rights_scopes';
        $mutexId = $mutex === 'rights_scopes' ? $f['scope']->id : PromotionUse::sole()->promotion_campaign_id;
        $input = $this->input($f, $gateway) + ['mutex' => $mutex, 'ordinal' => 1];
        if (isset($f['promotion'])) {
            $input['promotion'] = $f['promotion'];
        }
        $results = $this->race([$input + ['operation' => 'release'], $input + ['operation' => $resource === 'last_promotion_use' ? 'price' : 'hold', 'quote_id' => $other->public_id]], $mutex, $mutexId);
        $this->assertSame(['released', $resource === 'last_promotion_use' ? 'priced' : 'held'], array_column($results, 'result'));
        $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $f['order']->quote_id, 'state' => 'released']);
        if ($resource === 'shared_scope') {
            $this->assertDatabaseHas('inventory_reservations', ['quote_id' => $other->id, 'state' => 'held']);
        } else {
            // Legacy pricing allocates promotion capacity only; inventory is a separate command.
            $this->assertDatabaseCount('inventory_reservations', 1);
        }
        if ($resource === 'last_promotion_use') {
            $this->assertSame(['released', 'held'], PromotionUse::orderBy('id')->pluck('state')->all());
            $this->assertSame(1, PromotionUse::whereIn('state', ['held', 'pending', 'consumed'])->count());
        }
        foreach (['verified_payments', 'license_grants', 'exclusive_sales'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    private function input(array $f, object $gateway): array
    {
        return ['public_id' => $f['order']->public_id, 'intent_id' => $f['intent']->id, 'actor_id' => $f['admin']->id,
            'media_root' => Storage::disk('local')->path(''),
            'request_id' => (string) Str::uuid(), 'session' => $gateway->session, 'payment' => $gateway->payment, 'now' => now()->toIso8601ZuluString()];
    }

    private function race(array $inputs, string $table, int $id): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/unpaid-race-'.Str::uuid());
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
                    $this->assertContains($call['operation'], ['account', 'retrieve', 'payment_intent']);
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
        $this->fail('Unpaid workers missed the exact lock/barrier state.');
    }
}
