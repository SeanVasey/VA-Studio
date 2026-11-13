<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Notifications\Mail\TransactionalMailAdapter;
use App\Domain\Notifications\Mail\TransactionalMailScanSchema;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableNativeDatabase;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalMailFixtures as M;
use Tests\Support\TransactionalNotificationFixtures as N;
use Tests\TestCase;

class TransactionalMailRecoveryNativeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Separate native mail progress and queued-claim ownership.');
        }
        $this->fakePrivateMediaStorage();
        Mail::fake();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker['process']->stop(0);
            (new Filesystem)->deleteDirectory($worker['directory']);
        }
        foreach (glob(storage_path('framework/testing/mail-preflight-*'), GLOB_ONLYDIR) ?: [] as $path) {
            (new Filesystem)->deleteDirectory($path);
        }
        parent::tearDown();
    }

    public function test_native_concurrent_progress_reservations_have_distinct_committed_ids_and_no_stale_cycle_write(): void
    {
        $one = N::ready(suffix: 'RESERVEONE');
        $two = N::ready(suffix: 'RESERVETWO');
        M::configure();
        $adapter = app(TransactionalMailAdapter::class);
        $ids = [$adapter->queueOrderReady($one['notice']['notificationId'])['deliveryId'], $adapter->queueOrderReady($two['notice']['notificationId'])['deliveryId']];
        $a = $this->start(['mode' => 'reserve']);
        $b = $this->start(['mode' => 'reserve']);
        DB::beginTransaction();
        DB::table(TransactionalMailScanSchema::TABLE)->where('scan_key', 'queued_wakeups')->lockForUpdate()->first();
        try {
            touch($a['directory'].'/start');
            touch($b['directory'].'/start');
            $parent = (int) DB::getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
            foreach ([$a, $b] as $worker) {
                $this->await(fn (): bool => $this->waiting($worker['connection'], $parent), $worker['process']);
            }
        } finally {
            DB::commit();
        }
        $results = [$this->finish($a), $this->finish($b)];
        $actual = array_column(array_column($results, 'result'), 'public_id');
        sort($actual);
        sort($ids);
        $this->assertSame($ids, $actual);
        $this->assertSame([null, null], array_column($results, 'stale'));
        $row = DB::table(TransactionalMailScanSchema::TABLE)->where('scan_key', 'queued_wakeups')->first();
        $this->assertSame(1, (int) $row->cycle);
        $this->assertSame((int) $row->high_water_id, (int) $row->after_id);
        $this->assertSame(3, (int) $row->version);
        $this->assertDatabaseCount('transactional_mail_deliveries', 2);
        Mail::assertNothingOutgoing();
    }

    public function test_native_duplicate_jobs_have_one_committed_claim_and_one_fake_transport_without_terminal_reopen(): void
    {
        $f = N::ready(suffix: 'NATIVECLAIM');
        M::configure();
        $id = app(TransactionalMailAdapter::class)->queueOrderReady($f['notice']['notificationId'])['deliveryId'];
        $input = ['mode' => 'deliver', 'delivery_id' => $id, 'policy' => config('transactional-mail'),
            'mailer_name' => M::MAILER, 'mailer' => config('mail.mailers.'.M::MAILER),
            // The fixtures enable these test policies in-process only; the worker must re-verify under the same ones.
            'authority' => ['customer' => config('customer'), 'delivery' => config('delivery'), 'payments' => config('payments'),
                'transactional-notifications' => config('transactional-notifications')]];
        $a = $this->start($input);
        $b = $this->start($input);
        touch($a['directory'].'/start');
        touch($b['directory'].'/start');
        $this->await(fn (): bool => is_file($a['directory'].'/transport') || is_file($b['directory'].'/transport'), $a['process'], $b['process']);
        $winner = is_file($a['directory'].'/transport') ? $a : $b;
        $loser = $winner['directory'] === $a['directory'] ? $b : $a;
        $this->await(fn (): bool => ! $loser['process']->isRunning(), $winner['process']);
        $noSend = $this->finish($loser);
        $this->assertSame(0, $noSend['sent']);
        $this->assertSame('submitting', $noSend['result']['state']);
        $this->assertDatabaseHas('transactional_mail_deliveries', ['public_id' => $id, 'state' => 'submitting', 'version' => 1]);
        touch($winner['directory'].'/release');
        $sent = $this->finish($winner);
        $this->assertSame(1, $sent['sent']);
        $this->assertSame('accepted', $sent['result']['state']);
        $before = DB::table('transactional_mail_deliveries')->where('public_id', $id)->first();
        $this->assertFalse(app(TransactionalMailAdapter::class)->wakeQueued($id));
        $this->assertSame((array) $before, (array) DB::table('transactional_mail_deliveries')->where('public_id', $id)->first());
        Mail::assertNothingOutgoing(); // The sole fake send occurred in the separately witnessed winner.
    }

    private function start(array $input): array
    {
        $directory = storage_path('framework/testing/cx-mail-native-'.Str::uuid());
        (new Filesystem)->makeDirectory($directory, 0700, true);
        $configuration = DB::connection()->getConfig();
        // The shared declaration rule: a local schema named by VA_LOCAL_DISPOSABLE_MYSQL, or CI's VA_CI_DISPOSABLE_MYSQL=1 marker on
        // the job's own disposable DB_DATABASE (the marker was once the schema name; Foundation run 43304362814 refused it).
        $this->assertTrue(DisposableNativeDatabase::isAdmitted((string) getenv('VA_LOCAL_DISPOSABLE_MYSQL')),
            'The parent must already hold its genuine disposable-native declaration.');
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
            'DB_URL' => '', 'DB_SOCKET' => (string) ($configuration['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync'];
        foreach (['VA_LOCAL_DISPOSABLE_MYSQL', 'VA_CI_DISPOSABLE_MYSQL', 'CI', 'GITHUB_ACTIONS'] as $key) {
            $environment[$key] = getenv($key);
        }
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) $configuration[$key];
        }
        $process = new Process([PHP_BINARY, base_path('tests/Support/transactional-mail-recovery-worker.php')], base_path(), $environment,
            json_encode($input + ['directory' => $directory, 'private_root' => Storage::disk('local')->path('')], JSON_THROW_ON_ERROR), 90);
        $worker = compact('process', 'directory');
        $this->workers[] = $worker;
        $process->start();
        $this->await(fn (): bool => is_file($directory.'/ready'), $process);
        $ready = json_decode(file_get_contents($directory.'/ready'), true, 8, JSON_THROW_ON_ERROR);
        $this->assertNotSame(getmypid(), $ready['pid']);
        $this->assertNotSame((int) DB::getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn(), $ready['connection']);

        return $worker + $ready;
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), 'The bounded original worker did not complete.');
        $this->assertSame('', $worker['process']->getErrorOutput());
        $value = json_decode($worker['process']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame('ok', $value['status']);
        $this->assertSame($worker['pid'], $value['pid']);
        $this->assertSame($worker['connection'], $value['connection']);
        $this->assertSame(0, $value['transaction_level']);
        $this->assertFalse($value['in_transaction']);
        $this->assertTrue($value['waits_unchanged']);

        return $value;
    }

    private function waiting(int $requester, int $blocker): bool
    {
        return DB::table('performance_schema.data_lock_waits as w')
            ->join('performance_schema.threads as r', 'r.THREAD_ID', '=', 'w.REQUESTING_THREAD_ID')
            ->join('performance_schema.threads as b', 'b.THREAD_ID', '=', 'w.BLOCKING_THREAD_ID')
            ->join('performance_schema.data_locks as l', 'l.ENGINE_LOCK_ID', '=', 'w.REQUESTING_ENGINE_LOCK_ID')
            ->where('r.PROCESSLIST_ID', $requester)->where('b.PROCESSLIST_ID', $blocker)
            ->where('l.OBJECT_SCHEMA', DB::getDatabaseName())->where('l.OBJECT_NAME', TransactionalMailScanSchema::TABLE)
            ->where('l.INDEX_NAME', 'PRIMARY')->where('l.LOCK_TYPE', 'RECORD')->where('l.LOCK_STATUS', 'WAITING')->exists();
    }

    private function await(callable $condition, Process $process, ?Process $alternate = null): void
    {
        $end = hrtime(true) + 10_000_000_000;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            if (! $process->isRunning() && ($alternate === null || ! $alternate->isRunning())) {
                $this->fail('Native worker ended before its required witness.');
            }
            $process->checkTimeout();
            $alternate?->checkTimeout();
            usleep(10000);
        } while (hrtime(true) < $end);
        $this->fail('Native worker did not reach its bounded witness.');
    }
}
