<?php

namespace Tests\Feature\CustomerComms;

use App\Domain\Customers\DataRights\CustomerDataRights;
use App\Domain\Customers\DataRights\Models\CustomerDataRequest;
use App\Domain\Customers\Models\CustomerAccount;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Models\User;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionDataRightsFixtures;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/**
 * BL-44, native MySQL: the same user-row serialization as CustomerDataRightsConcurrencyTest, for a PRODUCTION customer. The command
 * first locks the customer user row, then the account, then re-reads the production identity evidence under those locks, so a
 * competing change committed while the worker waits on that row decides the worker's outcome. Skipped on SQLite.
 */
class ProductionDataRightsConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionDataRightsFixtures;
    use ProductionIdentityFixture;

    private const RETENTION = ['policy_reference' => 'Synthetic SR-1', 'financial_records_years' => 7, 'consent_records_years' => 3,
        'correspondence_records_years' => 2, 'security_records_years' => 1, 'evidence_records_years' => 7, 'abandoned_checkout_days' => 30,
        'operational_records_days' => 14];

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Production customer data request races require independent MySQL sessions and exact row waits.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('n', 32))]);
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY, 'customer.test_accounts_enabled' => false,
            'customer-data-rights.enabled' => true, 'customer-data-rights.retention' => self::RETENTION,
            'production-account-features.enabled' => true, 'production-account-features.provenance' => IdentityPolicy::REHEARSAL,
            'production-account-features.versions' => ProductionAccountFeaturePolicy::VERSIONS]);
        Queue::fake();
        $this->travelTo(now()->startOfSecond());
    }

    /** A customer the worker acts as, with the library the approval erases. */
    private function populated(string $email): array
    {
        $customer = $this->customer($email);
        $this->populate($customer, 'SYNTHETIC native private playlist');

        return $customer;
    }

    public function test_an_export_committed_while_a_production_approval_waits_is_purged_by_that_approval(): void
    {
        $alice = $this->populated('alice-native@example.test');
        $this->signIn($alice);
        $request = app(CustomerDataRights::class)->requestProductionDeletion($alice['principal'], $alice['user'])['requestId'];
        $staff = $this->staff();
        $result = $this->race(['operation' => 'approve', 'production' => true, 'actor_id' => $staff->id, 'request_id' => $request, 'version' => 0], $alice['user']->id,
            function () use ($alice): void {
                // The export transaction holds the same customer user row the approval waits for, and commits first.
                $now = now()->startOfSecond();
                $id = DB::table('customer_data_requests')->insertGetId(['public_id' => '77777777-7777-4777-8777-777777777777',
                    'customer_account_id' => $alice['principal']->accountId, 'kind' => 'export', 'state' => 'ready', 'open_slot' => 1, 'version' => 0,
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
        $this->assertSame(['purged', 'erased', 'retained'], [$result['value']['actions']['pending_exports'], $result['value']['actions']['production_listening_library'],
            $result['value']['actions']['production_feature_bindings']]);
        $export = DB::table('customer_data_requests')->where('public_id', '77777777-7777-4777-8777-777777777777')->sole();
        $this->assertSame(['expired', null], [$export->state, $export->open_slot]);
        $this->assertNull(DB::table('customer_data_exports')->where('request_id', $export->id)->value('ciphertext'));
        $this->assertFalse(CustomerAccount::find($alice['principal']->accountId)->active);
        $library = DB::table('production_listening_libraries')->sole();
        $this->assertSame(2, (int) $library->version);
        $this->assertSame([], json_decode(Crypt::decryptString($library->payload), true, 32, JSON_THROW_ON_ERROR)['playlists']);
    }

    public function test_an_export_waiting_behind_a_production_account_withdrawal_is_denied_without_rows(): void
    {
        $alice = $this->populated('alice-withdrawn@example.test');
        $result = $this->race(['operation' => 'export', 'production' => true, 'mint' => $alice['user']->id, 'actor_id' => $alice['user']->id], $alice['user']->id,
            function () use ($alice): void {
                $account = CustomerAccount::whereKey($alice['principal']->accountId)->lockForUpdate()->sole();
                $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
            });
        $this->assertSame('denied', $result['result'], json_encode($result));
        $this->assertDatabaseCount('customer_data_requests', 0);
        $this->assertDatabaseCount('customer_data_exports', 0);
    }

    public function test_an_approval_committed_while_a_production_deletion_request_waits_denies_the_request(): void
    {
        $alice = $this->populated('alice-late@example.test');
        $this->signIn($alice);
        $first = app(CustomerDataRights::class)->requestProductionDeletion($alice['principal'], $alice['user'])['requestId'];
        $staff = $this->staff();
        $result = $this->race(['operation' => 'deletion', 'production' => true, 'mint' => $alice['user']->id, 'actor_id' => $alice['user']->id], $alice['user']->id,
            function () use ($first, $staff): void {
                $now = now()->startOfSecond();
                $request = CustomerDataRequest::where('public_id', $first)->lockForUpdate()->sole();
                $account = CustomerAccount::whereKey($request->customer_account_id)->lockForUpdate()->sole();
                $account->update(['active' => false, 'access_version' => $account->access_version + 1]);
                DB::table('customer_data_requests')->where('id', $request->id)->update(['state' => 'completed', 'open_slot' => null, 'version' => 1,
                    'updated_at' => $now, 'resolved_at' => $now, 'resolved_by' => $staff->id, 'resolution_hash' => str_repeat('e', 64)]);
            });
        $this->assertSame('denied', $result['result'], json_encode($result));
        $this->assertSame(1, DB::table('customer_data_requests')->where('kind', 'deletion')->count());
    }

    public function test_two_simultaneous_production_deletion_requests_leave_one_request_and_one_replay(): void
    {
        $alice = $this->populated('alice-twice@example.test');
        $directory = storage_path('framework/testing/customer-data-rights-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $workers = [];
        try {
            foreach (['one', 'two'] as $name) {
                $workers[$name] = $this->worker($directory, ['name' => $name, 'barrier' => 'start', 'operation' => 'deletion', 'production' => true,
                    'mint' => $alice['user']->id, 'actor_id' => $alice['user']->id]);
                $workers[$name]->start();
            }
            $deadline = microtime(true) + 30;
            while (! (is_file($directory.'/one') && is_file($directory.'/two')) && microtime(true) < $deadline) {
                usleep(10000);
                clearstatcache();
            }
            touch($directory.'/start');
            $results = [];
            foreach ($workers as $name => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[$name] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame('saved', $results[$name]['result'], json_encode($results[$name]));
                $this->assertSame(0, $results[$name]['transaction_level']);
            }
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            $fs->deleteDirectory($directory);
        }
        $replayed = array_map(fn (array $r): bool => $r['value']['replayed'], array_values($results));
        sort($replayed);
        $this->assertSame([false, true], $replayed, 'Exactly one worker created the request; the other was handed it.');
        $this->assertSame($results['one']['value']['requestId'], $results['two']['value']['requestId']);
        $this->assertSame(1, DB::table('customer_data_requests')->where('kind', 'deletion')->count());
        $this->assertSame(1, DB::table('customer_data_request_events')->count());
    }

    private function worker(string $directory, array $input): Process
    {
        $input += ['at' => now()->toIso8601String(), 'retention' => self::RETENTION];

        return new Process([PHP_BINARY, base_path('tests/Support/customer-data-rights-worker.php')], base_path(), $this->environment($directory), json_encode($input, JSON_THROW_ON_ERROR), 90);
    }

    private function environment(string $directory): array
    {
        $database = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => (string) $database['database'],
            'DB_USERNAME' => (string) $database['username'], 'DB_PASSWORD' => (string) $database['password'], 'DB_SOCKET' => (string) ($database['unix_socket'] ?? ''),
            'DB_CHARSET' => (string) $database['charset'], 'DB_COLLATION' => (string) $database['collation'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'VASEY_DATA_RIGHTS_RACE_DIRECTORY' => $directory];
    }

    /** The parent holds the customer user row, waits until the worker blocks on exactly that row, commits $change, then reads the result. */
    private function race(array $input, int $userId, callable $change): array
    {
        $directory = storage_path('framework/testing/customer-data-rights-'.Str::uuid());
        $fs = new Filesystem;
        $fs->makeDirectory($directory, 0700, true);
        $process = null;
        try {
            $process = $this->worker($directory, $input);
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
