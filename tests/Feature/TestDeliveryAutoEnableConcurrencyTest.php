<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\Models\TestDeliveryControl;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\ContractFixtures;
use Tests\Support\DeliveryFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * D-19 amendment (2026-11-12): the optional automatic enable against a second independent MySQL connection. The parent holds the
 * order row lock until both workers are waiting on it, so both decide under the same order → control lock order the staff path uses.
 * Whatever the interleaving, one control exists and enabling bumps its version once.
 */
class TestDeliveryAutoEnableConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const POLICY = '{"schema_version":1,"purpose":"test_delivery_auto_enable","version":"test-delivery-auto-enable-v1","provider":"stripe",'
        .'"mode":"test","scope":"ready_orders_never_controlled","lanes":"track_and_sound_kit","holds":"skip_while_refund_dispute_held",'
        .'"staff_decisions":"final","actor":"system"}';

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Automatic-enable races require independent MySQL processes.');
        }
    }

    public function test_two_concurrent_automatic_enables_record_one_control_at_version_one(): void
    {
        $f = $this->ready();
        $results = $this->race($f, ['auto', 'auto']);
        $steps = array_merge(...array_column($results, 'steps'));
        sort($steps);
        $this->assertSame(['enabled', 'unchanged'], $steps);
        $control = TestDeliveryControl::query()->sole();
        $this->assertSame([false, 1], [$control->blocked, $control->control_version]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.auto_enabled')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.control_changed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.control_provisioned')->count());
    }

    public function test_a_concurrent_staff_enable_and_automatic_enable_end_with_one_enabled_control_and_one_change(): void
    {
        $f = $this->ready();
        $results = $this->race($f, ['auto', 'staff']);
        $auto = collect($results)->firstWhere('role', 'auto')['steps'];
        $staff = collect($results)->firstWhere('role', 'staff')['steps'];
        // Either the automatic enable won (staff's version-0 steps then conflict), or staff provisioned first (the automatic enable
        // leaves the order alone and staff's own enable proceeds).
        $this->assertContains([$auto, $staff], [[['enabled'], ['refused_conflict', 'refused_conflict']], [['unchanged'], ['blocked_0', 'enabled_1']]]);
        $control = TestDeliveryControl::query()->sole();
        $this->assertSame([false, 1], [$control->blocked, $control->control_version]);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.control_changed')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.delivery.control_provisioned')->count());
        $this->assertSame($auto === ['enabled'] ? 1 : 0, DB::table('audit_events')->where('action', 'commerce.delivery.auto_enabled')->count());
    }

    private function ready(): array
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());

        return F::ready($gateway, enable: false);
    }

    /** @return list<array{role: string, steps: list<string>, pid: int, transaction_level: int}> */
    private function race(array $f, array $roles): array
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/auto-enable-race-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $db = DB::connection()->getConfig();
        $input = json_encode(['roles' => $roles, 'order_public_id' => $f['order']->public_id, 'policy' => self::POLICY,
            'now' => now()->toIso8601ZuluString()], JSON_THROW_ON_ERROR);
        $processes = [];
        try {
            foreach ([0, 1] as $worker) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/delivery-auto-enable-race-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => (string) config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
                    'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_DELIVERY_RACE_DIRECTORY' => $directory, 'VASEY_DELIVERY_RACE_WORKER' => (string) $worker,
                    'VASEY_DELIVERY_RACE_MEDIA_ROOT' => Storage::disk('local')->path('')], $input, 55);
                $process->start();
                $processes[] = $process;
            }
            $this->until($processes, fn () => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), 'startup');
            $workers = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            // Hold the order row until both workers wait for it, then release them together.
            DB::beginTransaction();
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS connection_id')->connection_id;
            $this->assertCount(3, array_unique([...$workers, $parent]));
            Order::query()->whereKey($f['order']->id)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->until($processes, fn (): bool => (int) DB::selectOne('SELECT COUNT(DISTINCT r.PROCESSLIST_ID) AS c FROM performance_schema.data_lock_waits w'
                .' JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID'
                .' JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID WHERE b.PROCESSLIST_ID = ?', [$parent])->c >= 2, 'both waiting on the order lock');
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 8, JSON_THROW_ON_ERROR);
                $this->assertSame(0, $result['transaction_level']);
                $results[] = $result;
            }

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $filesystem->deleteDirectory($directory);
        }
    }

    private function until(array $processes, callable $predicate, string $stage): void
    {
        $deadline = microtime(true) + 25;
        do {
            clearstatcache();
            if ($predicate()) {
                return;
            }
            foreach ($processes as $process) {
                $process->checkTimeout();
                if (! $process->isRunning() && $process->getExitCode() !== 0) {
                    $this->fail('Race worker failed at '.$stage.': '.$process->getOutput().$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Race workers missed the '.$stage.' barrier.');
    }
}
