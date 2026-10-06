<?php

namespace Tests\Feature;

use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Notifications\PrivateNotificationCapture;
use App\Domain\Notifications\TestTransactionalNotifications;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\TransactionalNotificationFixtures as F;
use Tests\TestCase;

class TransactionalNotificationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private string $directory;

    private array $processes = [];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Notification claims and authority fences require independent MySQL sessions and exact PRIMARY row waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        $this->directory = storage_path('framework/testing/notification-race-'.Str::uuid());
        (new Filesystem)->makeDirectory($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        if (isset($this->directory)) {
            (new Filesystem)->deleteDirectory($this->directory);
        }
        parent::tearDown();
    }

    public function test_two_independent_enqueues_share_one_intent_and_the_exact_loser_replays_without_writes(): void
    {
        $f = F::ready(enqueue: false);
        $winner = $this->worker($f, 'winner', 'enqueue', ['hold_event' => 'notification.test.intent_enqueued']);
        $follower = $this->worker($f, 'follower', 'enqueue');
        $winnerReady = $this->ready('winner', $winner);
        $followerReady = $this->ready('follower', $follower);
        $this->independent($winnerReady, $followerReady);
        touch($this->directory.'/winner-start');
        $this->await(fn () => is_file($this->directory.'/winner-held'), [$winner]);
        touch($this->directory.'/follower-start');
        $this->waitProof($followerReady, $winnerReady, 'users', $f['user']->id, [$winner, $follower]);
        touch($this->directory.'/winner-release');
        $a = $this->workerResult($winner, $winnerReady);
        $b = $this->workerResult($follower, $followerReady);
        $this->assertSame('saved', $a['outcome']);
        $this->assertSame('saved', $b['outcome']);
        $this->assertFalse($a['result']['replayed']);
        $this->assertSame(array_replace($a['result'], ['replayed' => true]), $b['result']);
        $this->assertSame(0, $a['capture_calls'] + $b['capture_calls']);
        $this->assertDatabaseCount('transactional_notices', 1);
        $this->assertDatabaseCount('transactional_notice_attempts', 0);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'notification.test.intent_enqueued')->count());
        $before = $this->graph();
        $this->travel(5)->seconds();
        $this->assertSame($b['result'], app(TestTransactionalNotifications::class)->enqueueOrderReady($f['order']->public_id, $f['principal']));
        $this->assertSame($before, $this->graph());
    }

    public function test_two_independent_dispatches_claim_once_and_the_loser_never_invokes_private_storage(): void
    {
        $f = F::ready();
        $winner = $this->worker($f, 'winner', 'dispatch', ['hold_event' => 'notification.test.lease_claimed', 'hold_capture' => true]);
        $follower = $this->worker($f, 'follower', 'dispatch');
        $aReady = $this->ready('winner', $winner);
        $bReady = $this->ready('follower', $follower);
        $this->independent($aReady, $bReady);
        touch($this->directory.'/winner-start');
        $this->await(fn () => is_file($this->directory.'/winner-held'), [$winner]);
        touch($this->directory.'/follower-start');
        $this->waitProof($bReady, $aReady, 'users', $f['user']->id, [$winner, $follower]);
        touch($this->directory.'/winner-release');
        $this->await(fn () => is_file($this->directory.'/winner-before-capture'), [$winner]);
        $b = $this->workerResult($follower, $bReady);
        $this->assertSame('saved', $b['outcome']);
        $this->assertSame('leased', $b['result']['state']);
        $this->assertSame(0, $b['capture_calls']);
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
        touch($this->directory.'/winner-capture-release');
        $a = $this->workerResult($winner, $aReady);
        $this->assertSame('accepted', $a['result']['state']);
        $this->assertSame(1, $a['capture_calls']);
        $this->assertDatabaseCount('transactional_notice_attempts', 1);
        $this->assertSame(3, DB::table('audit_events')->where('action', 'like', 'notification.test.%')->count());
        $id = $f['notice']['notificationId'];
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$id.'.json');
        $this->assertSame(DB::table('transactional_notice_attempts')->sole()->receipt_hash, hash_file('sha256', $path));
        $this->assertSame(0600, fileperms($path) & 0777);
        $before = $this->graph();
        $stat = $this->fileIdentity($path);
        $this->travel(5)->seconds();
        $this->assertSame('accepted', app(TestTransactionalNotifications::class)->dispatch($id)['state']);
        $this->assertSame($before, $this->graph());
        $this->assertSame($stat, $this->fileIdentity($path));
    }

    public static function withdrawnOperations(): array
    {
        return ['enqueue' => ['enqueue'], 'dispatch' => ['dispatch'], 'reconcile' => ['reconcile']];
    }

    #[DataProvider('withdrawnOperations')]
    public function test_withdrawal_committed_after_an_exact_user_wait_blocks_every_notification_entrypoint_without_capture(string $operation): void
    {
        $f = F::ready(enqueue: $operation !== 'enqueue');
        $before = $this->graph();
        $worker = $this->worker($f, 'follower', $operation);
        $ready = $this->ready('follower', $worker);
        $parent = ['connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, 'pid' => getmypid()];
        $this->independent($parent, $ready);
        DB::beginTransaction();
        User::whereKey($f['user']->id)->lockForUpdate()->firstOrFail();
        touch($this->directory.'/follower-start');
        $this->waitProof($ready, $parent, 'users', $f['user']->id, [$worker]);
        $account = CustomerAccount::whereKey($f['account']->id)->lockForUpdate()->firstOrFail();
        $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
        DB::commit();
        $result = $this->workerResult($worker, $ready);
        $this->assertSame('denied', $result['outcome']);
        $this->assertSame(0, $result['capture_calls']);
        $this->assertSame($before, $this->graph());
        $this->assertSame([], Storage::disk('local')->allFiles('transactional-notification-capture'));
        $this->assertFalse($account->fresh()->active);
        $this->assertSame(2, $account->fresh()->access_version);
    }

    public function test_a_late_completion_waits_for_the_reconciliation_winner_and_cannot_add_an_attempt_audit_or_file_write(): void
    {
        $f = F::ready();
        $service = app(TestTransactionalNotifications::class);
        $id = $f['notice']['notificationId'];
        $lease = $service->claim($id);
        $stored = app(PrivateNotificationCapture::class)->store($id, $lease->capture());
        $path = Storage::disk('local')->path('transactional-notification-capture/'.$id.'.json');
        $bytes = file_get_contents($path);
        $stat = $this->fileIdentity($path);
        $this->travel(31)->seconds();
        $winner = $this->worker($f, 'winner', 'reconcile', ['hold_event' => 'notification.test.capture_reconciled']);
        $follower = $this->worker($f, 'follower', 'complete', ['attempt_id' => $lease->attemptId,
            'expires_at' => $lease->expiresAt->toIso8601String(), 'token' => $lease->token(), 'capture' => $lease->capture(), 'receipt_hash' => $stored['receiptHash']]);
        $aReady = $this->ready('winner', $winner);
        $bReady = $this->ready('follower', $follower);
        $this->independent($aReady, $bReady);
        touch($this->directory.'/winner-start');
        $this->await(fn () => is_file($this->directory.'/winner-held'), [$winner]);
        touch($this->directory.'/follower-start');
        $this->waitProof($bReady, $aReady, 'users', $f['user']->id, [$winner, $follower]);
        touch($this->directory.'/winner-release');
        $a = $this->workerResult($winner, $aReady);
        $b = $this->workerResult($follower, $bReady);
        $this->assertSame('accepted', $a['result']['state']);
        $this->assertSame($a['result'], $b['result']);
        $this->assertSame(0, $a['capture_calls'] + $b['capture_calls']);
        $this->assertDatabaseCount('transactional_notice_attempts', 1);
        $attempt = DB::table('transactional_notice_attempts')->sole();
        $this->assertSame('accepted', $attempt->state);
        $this->assertSame('capture_reconciled', $attempt->reason);
        $this->assertSame($stored['receiptHash'], $attempt->receipt_hash);
        $this->assertSame(4, DB::table('audit_events')->where('action', 'like', 'notification.test.%')->count());
        $this->assertSame($bytes, file_get_contents($path));
        $this->assertSame($stat, $this->fileIdentity($path));
        $before = $this->graph();
        $this->assertSame('accepted', $service->complete($lease, $stored['receiptHash'])['state']);
        $this->assertSame($before, $this->graph());
    }

    private function worker(array $f, string $name, string $operation, array $extra = []): Process
    {
        $database = DB::connection()->getConfig();
        $input = $extra + ['name' => $name, 'operation' => $operation, 'order_id' => $f['order']->public_id,
            'notification_id' => $f['notice']['notificationId'] ?? null,
            'principal' => [$f['principal']->accountId, $f['principal']->userId, $f['principal']->ownerKey, $f['principal']->accessVersion, $f['principal']->credentialStamp],
            'media_root' => Storage::disk('local')->path(''), 'at' => now()->toIso8601String()];
        $process = new Process([PHP_BINARY, base_path('tests/Support/transactional-notification-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_NOTIFICATION_RACE_DIRECTORY' => $this->directory,
        ], json_encode($input, JSON_THROW_ON_ERROR), 40);
        $this->processes[] = $process;
        $process->start();

        return $process;
    }

    private function ready(string $name, Process $worker): array
    {
        $this->await(fn () => is_file($this->directory.'/'.$name.'-ready'), [$worker]);

        return json_decode(file_get_contents($this->directory.'/'.$name.'-ready'), true, 8, JSON_THROW_ON_ERROR);
    }

    private function independent(array $a, array $b): void
    {
        $this->assertNotSame($a['connection_id'], $b['connection_id']);
        $this->assertNotSame($a['pid'], $b['pid']);
    }

    private function workerResult(Process $process, array $ready): array
    {
        $process->wait();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame($ready['connection_id'], $result['connection_id']);
        $this->assertSame($ready['pid'], $result['pid']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertSame(0, $result['provider_calls']);
        $this->assertSame('users', $result['locks'][0]);
        $this->assertNotSame('unexpected', $result['outcome'], $result['error_class'] ?? '');

        return $result;
    }

    private function waitProof(array $requester, array $blocker, string $table, int $id, array $workers): void
    {
        $proof = null;
        $this->await(function () use (&$proof, $requester, $blocker, $table, $id): bool {
            $proof = $this->waiting($requester['connection_id'], $blocker['connection_id'], $table, $id);

            return $proof !== null;
        }, $workers);
        $this->assertSame('WAITING', $proof->lock_status);
        $this->assertSame('PRIMARY', $proof->index_name);
        $this->assertSame((string) $id, $proof->lock_data);
        echo json_encode(['notification_row_wait' => (array) $proof], JSON_THROW_ON_ERROR).PHP_EOL;
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): ?object
    {
        $sql = <<<'SQL'
SELECT requesting_thread.PROCESSLIST_ID AS requester, blocking_thread.PROCESSLIST_ID AS blocker,
       requested.OBJECT_SCHEMA AS database_name, requested.OBJECT_NAME AS table_name,
       requested.INDEX_NAME AS index_name, requested.LOCK_TYPE AS lock_type,
       requested.LOCK_STATUS AS lock_status, requested.LOCK_DATA AS lock_data
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requesting_thread ON requesting_thread.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocking_thread ON blocking_thread.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requesting_thread.PROCESSLIST_ID=? AND blocking_thread.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME=? AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' AND requested.LOCK_DATA=? LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::getDatabaseName(), $table, (string) $id]);
    }

    private function await(callable $condition, array $workers): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($workers as $worker) {
                $this->assertTrue($worker->isRunning(), 'Notification worker ended before its required barrier: '.$worker->getOutput().$worker->getErrorOutput());
                $worker->checkTimeout();
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Notification worker never reached its required exact row wait.');
    }

    private function graph(): array
    {
        $tables = ['transactional_notices', 'transactional_notice_attempts', 'audit_events'];

        return array_combine($tables, array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(), $tables));
    }

    private function fileIdentity(string $path): array
    {
        clearstatcache(true, $path);

        return array_intersect_key(stat($path), array_flip(['dev', 'ino', 'mode', 'nlink', 'uid', 'gid', 'size', 'mtime', 'ctime'])) + ['sha256' => hash_file('sha256', $path)];
    }
}
