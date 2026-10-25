<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Billing\BillingDatabaseInstant;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Billing\BillingSnapshots;
use App\Domain\Memberships\Billing\BillingSubscriptionApprovals;
use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipInvoiceBinding;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRows;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PDO;
use PDOException;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/**
 * InnoDB lock evidence for the customer index, probed with NOWAIT from an independent session. This is lock-holding
 * and lock-order evidence on one native server, plus one real same-plan writer in a separate process; it is not a
 * throughput claim. Since C4e the index takes exactly the owner's T23 rows and reads everything else (its window, the
 * owner's history and the shared plan, definition and profile rows) without locks (review F3, N1, N2).
 */
class MembershipNativeCustomerIndexTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    private ?PDO $independent = null;

    private ?Process $worker = null;

    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('InnoDB row locks are observed with NOWAIT from an independent native MySQL session.');
        }
        $this->memberActivationSetup();
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    protected function tearDown(): void
    {
        if ($this->independent?->inTransaction()) {
            $this->independent->rollBack();
        }
        $this->independent = null;
        if ($this->worker?->isRunning()) {
            $this->worker->stop(1);
        }
        if ($this->directory !== null) {
            (new Filesystem)->deleteDirectory($this->directory);
        }
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_native_index_locks_only_the_owner_rows_until_commit_then_releases_them(): void
    {
        $fixture = $this->indexFixture();
        $probes = $this->probes($fixture);
        $observed = null;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$observed, $probes): void {
            if ($observed === null) {
                // Inside the index transaction, after every read and its current proof: only the owner's T23 rows are
                // held. The fence, the shared plan, definition and profile rows and the owner's own membership rows
                // are free (review N1/N2: the index locks nothing another customer's transaction needs).
                $observed = $this->nowait($probes);
            }
        });
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertCount(1, $index['periods']);
        $expected = array_fill_keys(array_keys($probes), 0);
        $expected['owner_account'] = $expected['owner_user'] = 3572;
        $this->assertSame($expected, $observed);
        // After the commit and the read-only committed proof nothing is retained.
        $this->assertSame(array_fill_keys(array_keys($probes), 0), $this->nowait($probes));
    }

    public function test_native_index_waits_at_a_writer_held_owner_lock_and_retains_no_lock_after_refusal(): void
    {
        $fixture = $this->indexFixture();
        $probes = $this->probes($fixture);
        $writer = $this->independent();
        // An in-flight award, reserve, release, expire or activation of this owner holds the owner's T23 rows first.
        $writer->beginTransaction();
        $statement = $writer->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
        $statement->execute([$fixture['buyer']['principal']->userId]);
        $statement->fetchAll();
        DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
            $this->fail('The index must wait at the held owner lock.');
        } catch (MembershipException $error) {
            $this->assertContains($error->reason, ['index_refused', 'owner_refused']);
            $this->assertSame(1205, $this->driverCode($error));
        } finally {
            DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        // Only the owner's user row was held, so the 1205 wait was at the owner lock. After the refusal the writer,
        // still holding it, can take every other owner and membership row the index reads: nothing was retained.
        unset($probes['owner_user']);
        $this->assertSame(array_fill_keys(array_keys($probes), 0), $this->nowait($probes, $writer, false));
        $writer->rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_native_index_and_a_zero_period_index_complete_while_other_sessions_hold_the_fence_and_shared_rows(): void
    {
        $fixture = $this->indexFixture();
        $other = $this->enrollThroughLocalSmtp('native-index-other@example.test');
        $writer = $this->independent();
        // Another customer's in-flight award, reserve or activation on the same plan holds the fence, then the shared
        // plan row, then (activation) the definition and profile. The index no longer serializes behind any of them
        // (review N1): with a one-second wait bound both a member's and a zero-period customer's index complete.
        $writer->beginTransaction();
        $shared = $this->sharedProbes($fixture);
        foreach (['fence' => ['SELECT id FROM '.(new BillingAwardFence)->table().' WHERE id = ?', 1], ...$shared] as [$sql, $id]) {
            $statement = $writer->prepare($sql.' FOR UPDATE');
            $statement->execute([$id]);
            $this->assertCount(1, $statement->fetchAll());
        }
        DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            $read = MembershipCustomerRead::begin();
            $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
            $read->proveBeforeBytes();
            $empty = (new MembershipCustomerIndex)->index($other['principal'], $other['user']);
        } finally {
            DB::connection()->getPdo()->exec('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        $this->assertCount(1, $index['periods']);
        $this->assertSame([], $empty['periods']);
        // The writer held every one of those rows throughout: the test's own session still cannot take them.
        $this->assertSame(array_fill_keys(array_keys($shared), 3572), $this->nowait($shared, DB::connection()->getPdo()));
        $writer->rollBack();
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_native_index_never_waits_when_another_session_takes_the_definition_then_the_plan_mid_index(): void
    {
        // The C4d lock orders formed a cycle without the fence (review N2): a member index held the plan row and then
        // wanted the definition, while a definition-first locker (a zero-period index, or an activation in the other
        // order) held the definition and wanted the plan. Now the index holds and requests neither.
        $fixture = $this->indexFixture();
        $config = DB::connection()->getConfig();
        $other = new \mysqli($config['host'], $config['username'], $config['password'], $config['database'], (int) $config['port']);
        $other->query('SET SESSION innodb_lock_wait_timeout = 20');
        $shared = $this->sharedProbes($fixture);
        $real = Crypt::getFacadeRoot();
        $armed = true;
        $queued = false;
        $observed = null;
        $hook = Mockery::mock();
        $hook->shouldReceive('decryptString')->andReturnUsing(function ($ciphertext) use ($real, &$armed, &$queued, $other, $shared) {
            if ($armed && self::inside(MembershipCustomerIndex::class, 'period')) {
                // Mid-index, inside its period phase: definition first, then (asynchronously) the plan row.
                $armed = false;
                $other->begin_transaction();
                $other->query("SELECT id FROM production_member_definitions WHERE id = '".$shared['definition'][1]."' FOR UPDATE")->fetch_all();
                $other->query("SELECT id FROM production_membership_plan_versions WHERE id = '".$shared['plan'][1]."' FOR UPDATE", MYSQLI_ASYNC);
                $queued = true;
            }

            return $real->decryptString($ciphertext);
        });
        $hook->shouldReceive('encryptString')->andReturnUsing(fn ($plain) => $real->encryptString($plain));
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$observed, &$queued, $other): void {
            if ($queued && $observed === null) {
                // Still inside the index transaction: the other session's plan request has already been granted.
                $links = $errors = $rejected = [$other];
                $ready = mysqli_poll($links, $errors, $rejected, 5);
                $observed = $ready === 1 ? ($other->reap_async_query() !== false ? 'granted' : 'error '.$other->errno) : 'waiting';
            }
        });
        Crypt::swap($hook);
        try {
            $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
        } finally {
            Crypt::swap($real);
            if ($queued) {
                $other->rollback();
            }
            $other->close();
        }
        $this->assertTrue($queued, 'The hook ran inside the index period phase.');
        $this->assertSame('granted', $observed);
        $this->assertCount(1, $index['periods']);
    }

    public function test_native_same_plan_award_for_another_customer_runs_while_the_index_is_open(): void
    {
        // A real writer, not a held row: another customer's owned award on the same plan (fence, plan row, its own
        // owner, billing and membership rows) starts in a separate process with the index transaction. While the
        // index is still open the award must take the shared plan row (or finish), and it must never wait for a lock
        // the index holds. The index then commits and the award completes.
        $fixture = $this->indexFixture();
        $award = $this->otherCustomerAward($fixture);
        $worker = $this->awardWorker($award);
        $writer = json_decode(file_get_contents($this->directory.'/ready-0'), true, 8, JSON_THROW_ON_ERROR)['connection_id'];
        $parent = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $started = false;
        $waitedOnIndex = false;
        $overlap = null;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use (&$started): void {
            if (! $started && self::inside(MembershipCustomerIndex::class, 'collectOwned')) {
                $started = true;
                touch($this->directory.'/start-0');
            }
        });
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use (&$started, &$waitedOnIndex, &$overlap, $worker, $parent, $writer, $fixture): void {
            if (! $started || $overlap !== null) {
                return;
            }
            // Still inside the index transaction: wait until the writer holds the shared plan row or has finished.
            $deadline = hrtime(true) + 40_000_000_000;
            do {
                $waitedOnIndex = $waitedOnIndex || $this->blockedBy($parent);
                if (! $worker->isRunning()) {
                    $overlap = 'finished';
                } elseif ($this->holdsRow($writer, 'production_membership_plan_versions', $fixture['plan']['id'])) {
                    $overlap = 'holds_plan';
                }
                usleep(20000);
            } while ($overlap === null && hrtime(true) < $deadline);
            $overlap ??= 'neither';
        });
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
        $worker->wait();
        $result = json_decode($worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertTrue($started);
        $this->assertContains($overlap, ['holds_plan', 'finished'], 'The same-plan writer did not reach the shared plan row while the index was open.');
        $this->assertFalse($waitedOnIndex, 'The same-plan writer waited for a lock held by the index.');
        $this->assertCount(1, $index['periods']);
        $this->assertArrayHasKey('error_class', $result);
        $this->assertNull($result['error_class'], json_encode($result));
        $this->assertIsString($result['value']['period_id'] ?? null);
        $this->assertSame(2, DB::table('production_membership_paid_periods')->count());
    }

    public function test_native_index_waiting_behind_a_same_owner_writer_reads_that_writers_commit(): void
    {
        // Re-review R1: a same-owner writer holds the owner's users row; the index waits at its first statement. The
        // writer then commits a new sealed period for the owner. Because the index creates its read view only after
        // the owner row is granted, it lists the new period instead of refusing at the terminal proof.
        $fixture = $this->indexFixture();
        [$period, $event] = $this->sealedOlderPeriod($fixture);
        $config = DB::connection()->getConfig();
        $parent = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->directory = storage_path('framework/testing/membership-index-owner-writer-'.Str::uuid());
        (new Filesystem)->makeDirectory($this->directory, 0700, true);
        file_put_contents($this->directory.'/rows.json', json_encode(['period' => $period, 'event' => $event], JSON_THROW_ON_ERROR));
        $code = <<<'PHP'
[, $host, $port, $database, $user, $password, $userId, $parent, $directory] = $argv;
$open = fn () => new PDO('mysql:host='.$host.';port='.$port.';dbname='.$database, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$writer = $open();
$watch = $open();
$writer->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$writer->beginTransaction();
$lock = $writer->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE');
$lock->execute([$userId]);
$lock->fetchAll();
touch($directory.'/locked');
$self = (int) $writer->query('SELECT CONNECTION_ID()')->fetchColumn();
$waiters = [];
$deadline = microtime(true) + 100;
while ($waiters === [] && microtime(true) < $deadline) {
    // Any other transaction waiting for a lock on this private server is the index (the test's own connection).
    $trx = $watch->prepare("SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id <> ?");
    $trx->execute([$self]);
    $waiters = array_map('intval', $trx->fetchAll(PDO::FETCH_COLUMN));
    // INNODB_TRX is a cache that InnoDB refreshes only after 0.1 s without a read; faster polling keeps it stale.
    usleep(250000);
}
$rows = json_decode(file_get_contents($directory.'/rows.json'), true, 8, JSON_THROW_ON_ERROR);
foreach (['production_membership_paid_periods' => $rows['period'], 'production_membership_credit_events' => $rows['event']] as $table => $row) {
    $insert = $writer->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
    $insert->execute(array_values($row));
}
$writer->commit();
echo json_encode(['waiters' => $waiters, 'parent' => (int) $parent]);
PHP;
        $this->worker = new Process([PHP_BINARY, '-r', $code, '--', (string) $config['host'], (string) $config['port'], (string) $config['database'],
            (string) $config['username'], (string) $config['password'], (string) $fixture['buyer']['principal']->userId, (string) $parent, $this->directory], base_path(), null, null, 200);
        $this->worker->start();
        $deadline = hrtime(true) + 30_000_000_000;
        while (! is_file($this->directory.'/locked')) {
            clearstatcache();
            if (! $this->worker->isRunning() || hrtime(true) >= $deadline) {
                $this->fail('The same-owner writer did not lock the owner row: '.$this->worker->getErrorOutput());
            }
            usleep(5000);
        }
        $read = MembershipCustomerRead::begin();
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $read->proveBeforeBytes();
        $this->worker->wait();
        $this->assertSame(0, $this->worker->getExitCode(), $this->worker->getErrorOutput());
        $observed = json_decode($this->worker->getOutput(), true, 4, JSON_THROW_ON_ERROR);
        $this->assertSame([$parent], $observed['waiters'], 'The index waited at the owner row while the writer held it.');
        $this->assertSame([DB::table('production_membership_paid_periods')->where('account_id', $fixture['buyer']['principal']->accountId)
            ->orderByDesc('period_start')->value('id'), $period['id']], array_column($index['periods'], 'id'));
    }

    public function test_native_index_waiting_behind_a_same_owner_release_reads_the_released_history(): void
    {
        // Re-review R4: the history-side stale-snapshot race with a real writer. The owner's genuine release (separate
        // process) holds the owner lock with its release event written, at its committing delegate. The index waits at
        // the owner row; only then is the release allowed to commit. The index must list the reservation as released.
        $fixture = $this->indexFixture();
        $this->directory = storage_path('framework/testing/membership-index-release-'.Str::uuid());
        (new Filesystem)->makeDirectory($this->directory, 0700, true);
        $db = DB::connection()->getConfig();
        $honor = CarbonImmutable::parse($fixture['reservation']['honor_deadline'], 'UTC')->format('Y-m-d H:i:s.u');
        $input = ['role' => 'release', 'hold' => true, 'user_id' => (int) $fixture['buyer']['user']->id,
            'definition_id' => $fixture['member_authored']->definitionId, 'redemption_id' => $fixture['reservation']['redemption_id'],
            'invoice_id' => $fixture['invoice']['id'], 'confirm_at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'), 'operation_at' => $honor,
            'configuration' => ['production-customer-identity' => config('production-customer-identity'),
                'production-memberships' => config('production-memberships'), 'production-membership-billing' => config('production-membership-billing'),
                'member-grants' => config('member-grants'), 'filesystems.disks.local' => config('filesystems.disks.local'), 'media' => config('media')]];
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('P', 32)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBER_ACTIVATION_RELEASE_RACE_ONLY' => '1', 'VA_MEMBER_ACTIVATION_RELEASE_RACE_DIRECTORY' => $this->directory];
        $this->worker = new Process([PHP_BINARY, base_path('tests/Support/member-activation-release-race-worker.php')], base_path(), $environment,
            json_encode($input, JSON_THROW_ON_ERROR), 400);
        $this->worker->start();
        $this->awaitFile('ready-release', 150);
        $writer = json_decode(file_get_contents($this->directory.'/ready-release'), true, 8, JSON_THROW_ON_ERROR)['connection_id'];
        touch($this->directory.'/start-release');
        $this->awaitFile('held-release', 90);
        // A watcher lets the release commit only once the index waits at the owner row the release holds.
        $code = <<<'PHP'
[, $host, $port, $database, $user, $password, $writer, $directory] = $argv;
$watch = new PDO('mysql:host='.$host.';port='.$port.';dbname='.$database, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$waiters = [];
$deadline = microtime(true) + 15;
while ($waiters === [] && microtime(true) < $deadline) {
    $trx = $watch->prepare("SELECT trx_mysql_thread_id FROM information_schema.INNODB_TRX WHERE trx_state = 'LOCK WAIT' AND trx_mysql_thread_id <> ?");
    $trx->execute([$writer]);
    $waiters = array_map('intval', $trx->fetchAll(PDO::FETCH_COLUMN));
    // INNODB_TRX is a cache that InnoDB refreshes only after 0.1 s without a read; faster polling keeps it stale.
    usleep(250000);
}
touch($directory.'/commit-release');
echo json_encode(['waiters' => $waiters]);
PHP;
        $watcher = new Process([PHP_BINARY, '-r', $code, '--', (string) $db['host'], (string) $db['port'], (string) $db['database'],
            (string) $db['username'], (string) $db['password'], (string) $writer, $this->directory], base_path(), null, null, 60);
        $watcher->start();
        $parent = (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
        $read = MembershipCustomerRead::begin();
        $index = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $read->proveBeforeBytes();
        $watcher->wait();
        $this->worker->wait();
        $this->assertSame([$parent], json_decode($watcher->getOutput(), true, 4, JSON_THROW_ON_ERROR)['waiters'], $watcher->getErrorOutput());
        $this->assertSame(0, $this->worker->getExitCode(), $this->worker->getErrorOutput());
        $result = json_decode($this->worker->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertSame([], $result['errors']);
        $statuses = array_column($index['periods'][0]['reservations'], 'status', 'id');
        $this->assertSame('released', $statuses[$fixture['reservation']['redemption_id']]);
    }

    public function test_native_index_and_reserve_interleave_sequentially_and_pre_bytes_sees_the_change(): void
    {
        $fixture = $this->indexFixture();
        $read = MembershipCustomerRead::begin();
        $first = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user'], $read);
        $this->assertCount(1, $first['periods'][0]['reservations']);
        $this->reserveFixture($fixture, 'native-second-reservation');
        try {
            $read->proveBeforeBytes();
            $this->fail('A stale prebuilt body must not be released.');
        } catch (MembershipException $error) {
            $this->assertSame('changed_before_bytes', $error->reason);
        }
        $second = (new MembershipCustomerIndex)->index($fixture['buyer']['principal'], $fixture['buyer']['user']);
        $this->assertCount(2, $second['periods'][0]['reservations']);
        $this->assertSame(['available' => 1, 'reserved' => 2, 'consumed' => 0, 'expired' => 0], $second['periods'][0]['balances']);
    }

    /** The real activation fixture (award, reserve and the authored member definition); phase B runs it after G1. */
    protected function indexFixture(): array
    {
        return $this->memberActivationFixture();
    }

    private function probes(array $fixture): array
    {
        $probes = [
            'owner_account' => ['SELECT id FROM customer_accounts WHERE id = ?', $fixture['buyer']['principal']->accountId],
            'owner_user' => ['SELECT id FROM users WHERE id = ?', $fixture['buyer']['principal']->userId],
            'fence' => ['SELECT id FROM '.(new BillingAwardFence)->table().' WHERE id = ?', 1],
            ...$this->sharedProbes($fixture),
            'period' => ['SELECT id FROM production_membership_paid_periods WHERE account_id = ? ORDER BY id LIMIT 1', $fixture['buyer']['principal']->accountId],
        ];
        if (($fixture['reservation'] ?? null) !== null) {
            $probes['redemption'] = ['SELECT id FROM production_membership_redemptions WHERE id = ?', $fixture['reservation']['redemption_id']];
        }

        return $probes;
    }

    /** The rows every customer on the plan shares: plan version, approved member definition and its profile. */
    private function sharedProbes(array $fixture): array
    {
        return [
            'plan' => ['SELECT id FROM production_membership_plan_versions WHERE id = ?', $fixture['plan']['id']],
            'definition' => ['SELECT id FROM production_member_definitions WHERE id = ?', $fixture['member_authored']->definitionId],
            'profile' => ['SELECT id FROM production_member_profiles WHERE id = ?', $fixture['member_authored']->profileId],
        ];
    }

    private function awaitFile(string $name, int $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        while (! is_file($this->directory.'/'.$name)) {
            clearstatcache();
            if (! $this->worker->isRunning() || hrtime(true) >= $deadline) {
                $this->fail('Worker did not reach '.$name.': '.$this->worker->getOutput().$this->worker->getErrorOutput());
            }
            usleep(5000);
        }
    }

    /** One older sealed period and its award event for the same owner, in the exact retained format (not inserted). */
    private function sealedOlderPeriod(array $fixture): array
    {
        $real = (array) DB::table(MembershipPeriodRecords::PERIODS)->where('account_id', $fixture['buyer']['principal']->accountId)->sole();
        $award = (array) DB::table(MembershipPeriodRecords::EVENTS)->where('period_id', $real['id'])->where('kind', 'award')->sole();
        $plan = (array) DB::table(MembershipRecords::TABLE)->where('id', $real['plan_version_id'])->sole();
        $policy = MembershipPolicyVersions::document($plan)->binding($plan['id']);
        $originId = (int) $real['identity_origin_id'];
        $invoice = MembershipPeriodRecords::historicalInvoice($real, $award, $policy, $originId);
        $move = static fn (?string $at): ?string => $at === null ? null : gmdate('Y-m-d H:i:s', strtotime($at.' UTC') - 2678400);
        $copy = new MembershipInvoiceBinding(hash('sha256', 'native-index-owner-writer-invoice'), hash('sha256', 'native-index-owner-writer-graph'),
            $invoice->providerScopeHash, $invoice->amountMinor, $invoice->currency, $move($invoice->periodStart), $move($invoice->periodEnd),
            $invoice->planPolicyHash, $invoice->originalTermsHash, $invoice->originalBuyerBinding, $invoice->provenance, $move($invoice->paidAt),
            $invoice->planVersionId);

        return MembershipPeriodRecords::create($copy, $policy, $originId, $fixture['buyer']['principal']->durableBinding());
    }

    /** A second, verified customer's approved subscription and paid, observed invoice on the same plan. */
    private function otherCustomerAward(array $fixture): array
    {
        $references = [BillingStripeFixtures::CUSTOMER => 'cus_SYNTHETICINDEX2', BillingStripeFixtures::SUBSCRIPTION => 'sub_SYNTHETICINDEX2',
            BillingStripeFixtures::INVOICE => 'in_SYNTHETICINDEX2', BillingStripeFixtures::INVOICE_PAYMENT => 'inpay_SYNTHETICINDEX2',
            BillingStripeFixtures::PAYMENT_INTENT => 'pi_SYNTHETICINDEX2', BillingStripeFixtures::CHARGE => 'ch_SYNTHETICINDEX2',
            BillingStripeFixtures::BALANCE_TRANSACTION => 'txn_SYNTHETICINDEX2', 'il_SYNTHETIC' => 'il_SYNTHETICINDEX2', 'si_SYNTHETIC' => 'si_SYNTHETICINDEX2'];
        $replace = function (mixed $value) use (&$replace, $references): mixed {
            if (! is_array($value)) {
                return is_string($value) ? ($references[$value] ?? $value) : $value;
            }
            $result = [];
            foreach ($value as $key => $item) {
                $result[is_string($key) ? ($references[$key] ?? $key) : $key] = $replace($item);
            }

            return $result;
        };
        $buyer = $this->enrollThroughLocalSmtp('native-index-writer@example.test');
        $approved = DB::transaction(fn () => (new BillingSubscriptionApprovals)->approve($fixture['plan']['id'], $references[BillingStripeFixtures::CUSTOMER],
            $references[BillingStripeFixtures::SUBSCRIPTION], BillingStripeFixtures::PRICE, $buyer['principal'], $buyer['user'], $fixture['staff'], new MembershipRows));
        $ledger = new BillingLedger;
        $approval = $ledger->binding($approved['id'], (new BillingPolicy)->current());
        $invoice = $ledger->invoice($approval, $references[BillingStripeFixtures::INVOICE]);
        $start = $ledger->startRetrieval();
        $graph = $replace(BillingStripeFixtures::graph());
        $interval = $ledger->endRetrieval($start);
        $retrievedAt = BillingDatabaseInstant::fromNativeValue($interval->databaseAt, 0)->seconds();
        $projected = BillingStripeFixtures::snapshots($graph, $retrievedAt);
        $snapshots = new BillingSnapshots($references[BillingStripeFixtures::INVOICE], $projected->account, $projected->invoice,
            $projected->lines, $projected->payments, $projected->paymentIntents, $projected->charges,
            $projected->balanceTransactions, $projected->subscription, $retrievedAt, IdentityPolicy::REHEARSAL);
        $ledger->append($invoice, BillingSettlement::evaluate($snapshots, $approval['expectation']), IdentityPolicy::REHEARSAL, $interval);

        return ['buyer' => $buyer, 'invoice' => $invoice];
    }

    private function awardWorker(array $award): Process
    {
        $this->directory = storage_path('framework/testing/membership-index-award-'.Str::uuid());
        (new Filesystem)->makeDirectory($this->directory, 0700, true);
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('P', 32)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBERSHIP_AWARD_RACE_ONLY' => '1', 'VA_MEMBERSHIP_AWARD_RACE_DIRECTORY' => $this->directory, 'VA_MEMBERSHIP_AWARD_RACE_WORKER' => '0'];
        $input = ['operation' => 'award', 'user_id' => (int) $award['buyer']['user']->id, 'invoice_id' => $award['invoice']['id'],
            'configuration' => ['production-customer-identity' => config('production-customer-identity'),
                'production-memberships' => config('production-memberships'), 'production-membership-billing' => config('production-membership-billing')]];
        $this->worker = new Process([PHP_BINARY, base_path('tests/Support/membership-award-race-worker.php')], base_path(), $environment,
            json_encode($input, JSON_THROW_ON_ERROR), 200);
        $this->worker->start();
        $deadline = hrtime(true) + 30_000_000_000;
        while (! is_file($this->directory.'/ready-0')) {
            clearstatcache();
            if (! $this->worker->isRunning() || hrtime(true) >= $deadline) {
                $this->fail('Award worker did not reach its barrier: '.$this->worker->getOutput().$this->worker->getErrorOutput());
            }
            usleep(5000);
        }

        return $this->worker;
    }

    /** True while the given session holds a granted exclusive record lock on that row. */
    private function holdsRow(int $session, string $table, string $id): bool
    {
        $statement = $this->independent()->prepare("SELECT COUNT(*) FROM performance_schema.data_locks locks
            JOIN performance_schema.threads holder ON holder.THREAD_ID = locks.THREAD_ID
            WHERE holder.PROCESSLIST_ID = ? AND locks.OBJECT_NAME = ? AND locks.INDEX_NAME = 'PRIMARY' AND locks.LOCK_TYPE = 'RECORD'
            AND locks.LOCK_MODE LIKE 'X%' AND locks.LOCK_STATUS = 'GRANTED' AND locks.LOCK_DATA LIKE ?");
        $statement->execute([$session, $table, '%'.$id.'%']);

        return (int) $statement->fetchColumn() > 0;
    }

    /** True while any session waits for an InnoDB lock that the index's own connection holds. */
    private function blockedBy(int $blocker): bool
    {
        $statement = $this->independent()->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits waits
            JOIN performance_schema.threads blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID WHERE blocker.PROCESSLIST_ID = ?');
        $statement->execute([$blocker]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function inside(string $class, string $method): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 80) as $frame) {
            if (($frame['class'] ?? null) === $class && ($frame['function'] ?? null) === $method) {
                return true;
            }
        }

        return false;
    }

    /** 3572 = ER_LOCK_NOWAIT (a held lock); 0 = acquired. A probe that acquires is rolled back unless retained. */
    private function nowait(array $probes, ?PDO $session = null, bool $own = true): array
    {
        $session ??= $this->independent();
        $result = [];
        foreach ($probes as $name => [$sql, $id]) {
            if ($own) {
                $session->beginTransaction();
            }
            try {
                $statement = $session->prepare($sql.' FOR UPDATE NOWAIT');
                $statement->execute([$id]);
                $this->assertCount(1, $statement->fetchAll());
                $result[$name] = 0;
            } catch (PDOException $error) {
                $result[$name] = (int) ($error->errorInfo[1] ?? -1);
            } finally {
                if ($own) {
                    $session->rollBack();
                }
            }
        }

        return $result;
    }

    private function driverCode(\Throwable $error): ?int
    {
        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof PDOException) {
                return (int) ($cause->errorInfo[1] ?? -1);
            }
        }

        return null;
    }

    private function independent(): PDO
    {
        if ($this->independent === null) {
            $config = DB::connection()->getConfig();
            $this->independent = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],
                $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->independent->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        return $this->independent;
    }
}
