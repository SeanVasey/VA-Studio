<?php

namespace Tests\Feature;

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\ReservePricedQuote;
use App\Models\User;
use App\Support\Audit\AuditEvent;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class CommerceAuditActorConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Exact commerce actor waits require independent MySQL sessions; SQLite is not concurrency evidence.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        OrderFixtures::configure();
    }

    private function input(string $operation, User $actor, array $input = []): array
    {
        return $input + ['operation' => $operation, 'actor_id' => $actor->id, 'media_root' => Storage::disk('local')->path(''), 'at' => now()->toIso8601String()];
    }

    public static function ordering(): array
    {
        $cases = [];
        foreach (array_keys(CommerceAuditActorTest::operations()) as $operation) {
            foreach (['commerce first' => 0, 'manifest first' => 1] as $label => $first) {
                $cases[$operation.' / '.$label] = [$operation, $first];
            }
        }

        return $cases;
    }

    #[DataProvider('ordering')]
    public function test_manifest_and_each_customer_entrypoint_serialize_exact_actor_before_resources_in_both_orders(string $operation, int $first): void
    {
        $f = InventoryFixtures::selection();
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        if (in_array($operation, ['inventory-attempt', 'reserve-attempt', 'promotion-attempt', 'prepare', 'review'], true)) {
            app(ReservePricedQuote::class)->hold($f['quote']->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        }
        $row = $f['revision']->fresh()->getAttributes();
        $quote = $f['quote']->fresh()->getAttributes();
        $audits = AuditEvent::max('id');
        $input = ['track_id' => $f['track']->id, 'quote_id' => $f['quote']->public_id, 'items' => $f['items'], 'owner' => InventoryFixtures::OWNER, 'key' => (string) Str::uuid(), 'attempt' => (string) Str::uuid(), 'request' => $operation === 'prepare' ? OrderFixtures::request($f['quote']) : null];
        $inputs = [$this->input($operation, $f['actor'], $input), $this->input('manifest', $f['actor'], $input)];
        $inputs[$first] += $first === 0 ? ['pause_table' => 'tracks', 'pause_id' => $f['track']->id] : ['pause_table' => 'users', 'pause_id' => $f['actor']->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $f): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $f['actor']->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            foreach ($results as $result) {
                $this->assertSame('saved', $result['result'], json_encode($result));
                $this->assertSame('users', $result['locks'][0]['table']);
            }
        });
        $this->assertSame($row, $f['revision']->fresh()->getAttributes());
        $this->assertSame($quote, $f['quote']->fresh()->getAttributes());
        foreach (AuditEvent::where('id', '>', $audits)->get() as $audit) {
            $this->assertSame($f['actor']->id, $audit->actor_id);
        }
    }

    public static function checkoutOrdering(): array
    {
        return ['prepare first' => [0], 'checkout first' => [1]];
    }

    #[DataProvider('checkoutOrdering')]
    public function test_existing_order_replay_and_checkout_fence_actor_before_order_in_both_orders(int $first): void
    {
        CheckoutFixtures::configure();
        $f = OrderFixtures::priced();
        $key = (string) Str::uuid();
        $f['order'] = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, $key, OrderFixtures::request($f['quote']));
        $actor = User::factory()->unverified()->create();
        $order = $f['order']->fresh()->getAttributes();
        $attempt = $f['order']->attempt()->sole()->getAttributes();
        $audits = AuditEvent::max('id');
        $input = ['owner' => InventoryFixtures::OWNER, 'order_id' => $f['order']->public_id, 'order_row_id' => $f['order']->id, 'key' => $key, 'request' => OrderFixtures::request($f['quote'])];
        $inputs = [$this->input('prepare', $actor, $input), $this->input('checkout', $actor, $input)];
        $inputs[$first] += ['pause_table' => 'orders', 'pause_id' => $f['order']->id];
        $this->race($inputs, function ($directory, $processes, $connections) use ($first, $actor): void {
            $second = 1 - $first;
            touch($directory.'/start-'.$first);
            $this->await(fn () => is_file($directory.'/locked-'.$first), $processes);
            touch($directory.'/start-'.$second);
            $this->observeWait($connections[$second], $connections[$first], 'users', $actor->id, $processes);
            touch($directory.'/release-'.$first);
            $results = $this->results($processes, $connections);
            foreach ($results as $result) {
                $this->assertSame('saved', $result['result'], json_encode($result));
                $this->assertSame('users', $result['locks'][0]['table']);
            }
            foreach ($results[1]['provider_calls'] as $call) {
                $this->assertSame(0, $call['transaction_level']);
            }
        });
        $this->assertSame($order, $f['order']->fresh()->getAttributes());
        $this->assertSame($attempt, $f['order']->attempt()->sole()->getAttributes());
        $this->assertSame(1, DB::table('checkout_intents')->count());
        $this->assertSame(1, DB::table('checkout_sessions')->count());
        $new = AuditEvent::where('id', '>', $audits)->get();
        $this->assertCount(2, $new);
        $this->assertSame($actor->id, $new->firstWhere('action', 'commerce.checkout.initiated')->actor_id);
        $this->assertNull($new->firstWhere('action', 'commerce.checkout.bound')->actor_id);
    }

    public function test_explicit_guest_commits_while_ambient_customer_row_remains_locked(): void
    {
        $f = InventoryFixtures::selection();
        $actor = User::factory()->create();
        $audit = AuditEvent::max('id');
        $input = ['owner' => InventoryFixtures::OWNER, 'quote_id' => $f['quote']->public_id, 'items' => $f['items'], 'key' => (string) Str::uuid(), 'anonymous' => true];
        $inputs = [$this->input('lock-user', $actor, ['pause_table' => 'users', 'pause_id' => $actor->id]), $this->input('create', $actor, $input)];
        $this->race($inputs, function ($directory, $processes, $connections): void {
            touch($directory.'/start-0');
            $this->await(fn () => is_file($directory.'/locked-0'), $processes);
            touch($directory.'/start-1');
            $this->await(fn () => is_file($directory.'/finished-1'), $processes);
            $this->assertTrue($processes[0]->isRunning());
            touch($directory.'/release-0');
            $results = $this->results($processes, $connections);
            $this->assertSame('saved', $results[1]['result']);
            $this->assertNotContains('users', array_column($results[1]['locks'], 'table'));
        });
        foreach (AuditEvent::where('id', '>', $audit)->get() as $row) {
            $this->assertNull($row->actor_id);
        }
    }

    private function race(array $inputs, callable $coordinate): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $directory = storage_path('framework/testing/commerce-audit-actor-'.Str::uuid());
        $filesystem = new Filesystem;
        $filesystem->makeDirectory($directory, 0700, true);
        $processes = [];
        $database = DB::connection()->getConfig();
        try {
            foreach ($inputs as $worker => $input) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/commerce-audit-actor-worker.php')], base_path(), [
                    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                    'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'],
                    'DB_DATABASE' => (string) $database['database'], 'DB_USERNAME' => (string) $database['username'],
                    'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                    'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
                    'VASEY_COMMERCE_AUDIT_DIRECTORY' => $directory, 'VASEY_COMMERCE_AUDIT_WORKER' => (string) $worker,
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
            $this->assertSame(0, $process->getExitCode(), 'Commerce actor process failed: '.$process->getOutput().$process->getErrorOutput());
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
                $this->assertTrue($process->isRunning(), 'A commerce worker exited before the required barrier: '.$process->getOutput().$process->getErrorOutput());
                $process->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Commerce actors did not reach the required exact row wait/barrier.');
    }
}
