<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\CustomerAccess;
use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Domain\Customers\Models\CustomerAccount;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * Native MySQL serialization of customer data requests: every command takes the customer user row first, so a competing
 * committed change made while a worker waits on that exact row decides the worker's outcome.
 */
class CustomerDataRightsConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const RETENTION = ['policy_reference' => 'Synthetic SR-1', 'financial_records_years' => 7, 'consent_records_years' => 3,
        'correspondence_records_years' => 2, 'security_records_years' => 1];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Customer data request races require independent MySQL sessions and exact row waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        config(['customer-data-rights.enabled' => true, 'customer-data-rights.retention' => self::RETENTION]);
        $this->travelTo(now()->startOfSecond());
    }

    private function principal(array $fixture): array
    {
        $p = app(CustomerAccess::class)->principal($fixture['user']->fresh());

        return [$p->accountId, $p->userId, $p->ownerKey, $p->accessVersion, $p->credentialStamp];
    }

    public function test_a_deletion_request_committed_while_a_duplicate_waits_is_returned_instead_of_a_second_request(): void
    {
        $f = F::account();
        $result = $this->race(['operation' => 'deletion', 'actor_id' => $f['user']->id, 'principal' => $this->principal($f)], $f['user']->id,
            function () use ($f): void {
                $now = now()->startOfSecond();
                $id = DB::table('customer_data_requests')->insertGetId(['public_id' => '33333333-3333-4333-8333-333333333333',
                    'customer_account_id' => $f['account']->id, 'kind' => 'deletion', 'state' => 'received', 'open_slot' => 1, 'version' => 0,
                    'last_event_sequence' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $detail = CanonicalJson::encode(['kind' => 'deletion']);
                DB::table('customer_data_request_events')->insert(['request_id' => $id, 'sequence' => 1, 'event' => 'requested',
                    'detail' => $detail, 'detail_hash' => hash('sha256', $detail), 'created_at' => $now]);
                DB::table('customer_data_requests')->where('id', $id)->update(['last_event_sequence' => 1]);
            });
        $this->assertSame('saved', $result['result'], json_encode($result));
        $this->assertSame(['requestId' => '33333333-3333-4333-8333-333333333333', 'state' => 'received', 'replayed' => true], $result['value']);
        $this->assertDatabaseCount('customer_data_requests', 1);
    }

    public function test_a_rejection_committed_while_an_approval_waits_leaves_the_customer_untouched(): void
    {
        $f = F::account();
        $request = app(CustomerDataRights::class)->requestDeletion(app(CustomerAccess::class)->principal($f['user']), $f['user'])['requestId'];
        $staff = LicenseFixtures::admin();
        $staff->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $user = (array) DB::table('users')->where('id', $f['user']->id)->first();
        $result = $this->race(['operation' => 'approve', 'actor_id' => $staff->id, 'request_id' => $request, 'version' => 0], $f['user']->id,
            function () use ($request, $staff): void {
                $now = now()->startOfSecond();
                $row = CustomerDataRequest::where('public_id', $request)->lockForUpdate()->sole();
                $detail = CanonicalJson::encode(['schema' => 1, 'request_id' => $request, 'reason' => 'Synthetic competing rejection']);
                DB::table('customer_data_requests')->where('id', $row->id)->update(['state' => 'rejected', 'open_slot' => null, 'version' => 1,
                    'updated_at' => $now, 'resolved_at' => $now, 'resolved_by' => $staff->id, 'resolution_hash' => hash('sha256', $detail)]);
            });
        $this->assertSame(['refused', 'request_changed'], [$result['result'], $result['reason']], json_encode($result));
        $this->assertSame('rejected', CustomerDataRequest::sole()->state);
        $this->assertTrue(CustomerAccount::find($f['account']->id)->active);
        $this->assertSame($user, (array) DB::table('users')->where('id', $f['user']->id)->first());
    }

    public function test_an_export_committed_while_an_approval_waits_is_purged_by_that_approval(): void
    {
        $f = F::account();
        $request = app(CustomerDataRights::class)->requestDeletion(app(CustomerAccess::class)->principal($f['user']), $f['user'])['requestId'];
        $staff = LicenseFixtures::admin();
        $staff->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $result = $this->race(['operation' => 'approve', 'actor_id' => $staff->id, 'request_id' => $request, 'version' => 0], $f['user']->id,
            function () use ($f): void {
                // The export transaction holds the same customer user row the approval waits for, and commits first.
                $now = now()->startOfSecond();
                $id = DB::table('customer_data_requests')->insertGetId(['public_id' => '77777777-7777-4777-8777-777777777777',
                    'customer_account_id' => $f['account']->id, 'kind' => 'export', 'state' => 'ready', 'open_slot' => 1, 'version' => 0,
                    'last_event_sequence' => 0, 'created_at' => $now, 'updated_at' => $now]);
                $detail = CanonicalJson::encode(['kind' => 'export']);
                DB::table('customer_data_request_events')->insert(['request_id' => $id, 'sequence' => 1, 'event' => 'requested',
                    'detail' => $detail, 'detail_hash' => hash('sha256', $detail), 'created_at' => $now]);
                DB::table('customer_data_requests')->where('id', $id)->update(['last_event_sequence' => 1]);
                DB::table('customer_data_exports')->insert(['request_id' => $id, 'ciphertext' => encrypt('{"synthetic":true}', false),
                    'content_hash' => str_repeat('c', 64), 'token_hash' => str_repeat('d', 64), 'size_bytes' => 18,
                    'expires_at' => $now->copy()->addMinutes(15), 'created_at' => $now]);
            });
        $this->assertSame('saved', $result['result'], json_encode($result));
        $this->assertSame('purged', $result['value']['actions']['pending_exports']);
        $export = DB::table('customer_data_requests')->where('public_id', '77777777-7777-4777-8777-777777777777')->sole();
        $this->assertSame(['expired', null], [$export->state, $export->open_slot]);
        $this->assertNull(DB::table('customer_data_exports')->where('request_id', $export->id)->value('ciphertext'));
        $this->assertFalse(CustomerAccount::find($f['account']->id)->active);
    }

    public function test_an_export_waiting_behind_an_access_withdrawal_is_denied_without_rows(): void
    {
        $f = F::account();
        $result = $this->race(['operation' => 'export', 'actor_id' => $f['user']->id, 'principal' => $this->principal($f)], $f['user']->id,
            function () use ($f): void {
                $account = CustomerAccount::whereKey($f['account']->id)->lockForUpdate()->sole();
                $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
            });
        $this->assertSame('denied', $result['result'], json_encode($result));
        $this->assertDatabaseCount('customer_data_requests', 0);
        $this->assertDatabaseCount('customer_data_exports', 0);
    }

    public function test_a_download_committed_while_a_second_download_waits_cannot_return_the_export_twice(): void
    {
        $f = F::account();
        $ready = app(CustomerDataRights::class)->requestExport(app(CustomerAccess::class)->principal($f['user']), $f['user']);
        $result = $this->race(['operation' => 'download', 'actor_id' => $f['user']->id, 'principal' => $this->principal($f),
            'request_id' => $ready['requestId'], 'token' => $ready['downloadToken']], $f['user']->id, function () use ($ready): void {
                $now = now()->startOfSecond();
                $request = CustomerDataRequest::where('public_id', $ready['requestId'])->lockForUpdate()->sole();
                DB::table('customer_data_requests')->where('id', $request->id)->update(['state' => 'downloaded', 'open_slot' => null,
                    'version' => 1, 'updated_at' => $now, 'resolved_at' => $now]);
                DB::table('customer_data_exports')->where('request_id', $request->id)->update(['ciphertext' => null, 'consumed_at' => $now, 'purged_at' => $now]);
            });
        $this->assertSame(['refused', 'export_unavailable'], [$result['result'], $result['reason']], json_encode($result));
        $this->assertNull(DB::table('customer_data_exports')->value('ciphertext'));
    }

    /**
     * Re-review F7: two other customers request deletion at the same moment while staff approval of customer X is in flight and
     * holds every lock it takes (paused after its final audit write). Neither new request may wait on X's rows or on each other,
     * no deadlock may occur, and every request's event sequence and counter stay exact.
     */
    public function test_unrelated_customers_request_deletion_together_while_an_approval_is_in_flight_without_waits_or_deadlocks(): void
    {
        $x = F::account();
        $rights = app(CustomerDataRights::class);
        $rights->requestExport(app(CustomerAccess::class)->principal($x['user']->fresh()), $x['user']);
        $deletion = $rights->requestDeletion(app(CustomerAccess::class)->principal($x['user']->fresh()), $x['user'])['requestId'];
        // Created after X, so their requests and events land at the tails of the shared indexes, next to X's newest entries.
        $y = F::account();
        $z = F::account();
        $staff = LicenseFixtures::admin();
        $staff->forceFill(['app_authentication_secret' => 'JBSWY3DPEHPK3PXP'])->save();
        $deadlocks = fn (): int => (int) DB::selectOne("SELECT COUNT AS c FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'")->c;
        $before = $deadlocks();
        $directory = storage_path('framework/testing/customer-data-rights-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $procs = [];
        try {
            $procs['approve'] = $this->worker($directory, ['name' => 'approve', 'barrier' => null, 'operation' => 'approve', 'actor_id' => $staff->id,
                'request_id' => $deletion, 'version' => 0, 'pause_sql' => ['insert into `audit_events`'], 'pause_at' => 1, 'release' => 'release-approve']);
            $this->await(fn () => is_file($directory.'/approve.paused'), $procs['approve']);
            foreach (['y' => $y, 'z' => $z] as $name => $customer) {
                $procs[$name] = $this->worker($directory, ['name' => $name, 'barrier' => null, 'operation' => 'deletion', 'actor_id' => $customer['user']->id,
                    'principal' => $this->principal($customer), 'pause_sql' => ['from `customer_data_requests`', '`open_slot`'], 'pause_at' => 1, 'release' => 'release-yz']);
                $this->await(fn () => is_file($directory.'/'.$name.'.paused'), $procs[$name]);
            }
            $connections = array_map(fn (string $name): int => (int) file_get_contents($directory.'/'.$name.'.paused'), ['approve' => 'approve', 'y' => 'y', 'z' => 'z']);
            touch($directory.'/release-yz');
            $waits = [];
            $deadline = microtime(true) + 30;
            while (($procs['y']->isRunning() || $procs['z']->isRunning()) && microtime(true) < $deadline) {
                foreach (DB::select('SELECT r.PROCESSLIST_ID AS requester, b.PROCESSLIST_ID AS blocker FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.threads r ON r.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.threads b ON b.THREAD_ID = w.BLOCKING_THREAD_ID') as $wait) {
                    $waits[] = (array) $wait;
                }
                usleep(5000);
            }
            $this->assertFalse($procs['y']->isRunning() || $procs['z']->isRunning(), 'A new request waited for the approval in flight.');
            $this->assertTrue($procs['approve']->isRunning(), 'The approval finished before the new requests were checked.');
            $this->assertSame([], array_values(array_filter($waits, fn (array $w): bool => in_array((int) $w['requester'], $connections, true))));
            foreach (['y', 'z'] as $name) {
                $result = json_decode($procs[$name]->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame(['saved', false], [$result['result'], $result['value']['replayed']], json_encode($result));
            }
            touch($directory.'/release-approve');
            $procs['approve']->wait();
            $approval = json_decode($procs['approve']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame('saved', $approval['result'], json_encode($approval));
            $this->assertSame('purged', $approval['value']['actions']['pending_exports']);
        } finally {
            foreach ($procs as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $fs->deleteDirectory($directory);
        }
        $this->assertSame(0, $deadlocks() - $before, 'InnoDB detected a deadlock.');
        foreach (DB::table('customer_data_requests')->orderBy('id')->get() as $request) {
            $sequences = DB::table('customer_data_request_events')->where('request_id', $request->id)->orderBy('sequence')->pluck('sequence')->map(fn ($v) => (int) $v)->all();
            $this->assertSame(range(1, count($sequences)), $sequences);
            $this->assertSame(count($sequences), (int) $request->last_event_sequence);
        }
        $this->assertSame(2, DB::table('customer_data_requests')->whereIn('customer_account_id', [$y['account']->id, $z['account']->id])->where('state', 'received')->count());
    }

    private function worker(string $directory, array $input): Process
    {
        $input += ['at' => now()->toIso8601String(), 'retention' => self::RETENTION];
        $database = DB::connection()->getConfig();
        $process = new Process([PHP_BINARY, base_path('tests/Support/customer-data-rights-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_DATA_RIGHTS_RACE_DIRECTORY' => $directory,
        ], json_encode($input, JSON_THROW_ON_ERROR), 90);
        $process->start();

        return $process;
    }

    /** The parent holds the customer user row, waits until the worker blocks on exactly that row, commits $change, then reads the result. */
    private function race(array $input, int $userId, callable $change): array
    {
        $input += ['at' => now()->toIso8601String(), 'retention' => self::RETENTION];
        $directory = storage_path('framework/testing/customer-data-rights-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $database = DB::connection()->getConfig();
        $process = null;
        try {
            $process = new Process([PHP_BINARY, base_path('tests/Support/customer-data-rights-worker.php')], base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
                'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
                'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
                'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
                'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_DATA_RIGHTS_RACE_DIRECTORY' => $directory,
            ], json_encode($input, JSON_THROW_ON_ERROR), 40);
            $process->start();
            $this->await(fn () => is_file($directory.'/ready'), $process);
            $ready = json_decode(file_get_contents($directory.'/ready'), true, 16, JSON_THROW_ON_ERROR);
            $parent = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $this->assertNotSame($parent, $ready['connection_id']);
            DB::beginTransaction();
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            touch($directory.'/start');
            $this->await(fn () => $this->waiting($ready['connection_id'], $parent, $userId), $process);
            $change();
            DB::commit();
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            $this->assertSame($ready['connection_id'], $result['connection_id']);
            $this->assertSame(0, $result['transaction_level']);

            return $result;
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($process?->isRunning()) {
                $process->stop(1);
            }
            $fs->deleteDirectory($directory);
        }
    }

    private function waiting(int $requester, int $blocker, int $id): bool
    {
        $sql = <<<'SQL'
SELECT requested.LOCK_STATUS AS status
FROM performance_schema.data_lock_waits AS waits
JOIN performance_schema.threads AS requester ON requester.THREAD_ID=waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID=waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks AS requested ON requested.ENGINE_LOCK_ID=waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE=waits.ENGINE
WHERE waits.ENGINE='INNODB' AND requester.PROCESSLIST_ID=? AND blocker.PROCESSLIST_ID=?
AND requested.OBJECT_SCHEMA=? AND requested.OBJECT_NAME='users' AND requested.INDEX_NAME='PRIMARY'
AND requested.LOCK_TYPE='RECORD' AND requested.LOCK_STATUS='WAITING' AND requested.LOCK_DATA=? LIMIT 1
SQL;

        return DB::selectOne($sql, [$requester, $blocker, DB::connection()->getConfig('database'), (string) $id])?->status === 'WAITING';
    }

    private function await(callable $condition, Process $process): void
    {
        $deadline = microtime(true) + 15;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            $this->assertTrue($process->isRunning(), 'Customer data rights worker ended early: '.$process->getOutput().$process->getErrorOutput());
            $process->checkTimeout();
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Customer data rights worker never reached the exact user-row wait.');
    }
}
