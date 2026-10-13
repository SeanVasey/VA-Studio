<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Production\MembershipAwards;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipAwardFixtures;
use Tests\TestCase;

/** Separate processes on a disposable native database; synthetic facts never imply provider or host acceptance. */
class MembershipNativeAwardRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipAwardFixtures;

    private array $workers = [];

    private ?string $directory = null;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Owned first-award record waits and current reads require independent native MySQL connections.');
        }
        $this->awardSetup();
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->directory = storage_path('framework/testing/membership-award-'.Str::uuid());
        (new Filesystem)->makeDirectory($this->directory, 0700, true);
    }

    protected function tearDown(): void
    {
        try {
            // Release an owned parent barrier before terminating any waiting fixture worker.
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->workers as $worker) {
                if ($worker['process']->isRunning()) {
                    $worker['process']->stop(1);
                }
            }
            if ($this->directory !== null) {
                (new Filesystem)->deleteDirectory($this->directory);
            }
            CarbonImmutable::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public function test_concurrent_owned_awards_return_identical_ids_and_commit_one_period_and_one_event(): void
    {
        $fixture = $this->awardFixture();
        $first = $this->worker('award', $fixture);
        $second = $this->worker('award', $fixture);
        $parent = $this->connectionId();
        $this->assertCount(3, array_unique([$parent, $first['connection_id'], $second['connection_id']]));
        DB::beginTransaction();
        (new BillingAwardFence)->lock(DB::connection()->getPdo());
        $this->start($first);
        $this->await(fn (): bool => $this->waiting($first['connection_id'], $parent, BillingAwardFence::TABLE, 1), [$first]);
        $this->start($second);
        $this->await(fn (): bool => $this->waiting($second['connection_id'], $first['connection_id'], 'users', (int) $fixture['buyer']['user']->id), [$first, $second]);
        DB::commit();
        $one = $this->finish($first);
        $two = $this->finish($second);
        $this->assertSame($one['value'], $two['value']);
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $period = (array) DB::table(MembershipPeriodRecords::PERIODS)->sole();
        $event = (array) DB::table(MembershipPeriodRecords::EVENTS)->sole();
        $this->assertSame($period['id'], $one['value']['period_id']);
        $this->assertSame($event['id'], $one['value']['award_event_id']);
        $this->assertSame($period['id'], $event['period_id']);
        $this->assertSame('award', $event['kind']);
        $this->assertSame(1, (int) $event['sequence']);
        $this->assertCount(3, array_unique([getmypid(), $one['pid'], $two['pid']]));
    }

    public function test_hint_insert_waits_for_award_fence_then_refuses_committed_receipt_without_undoing_award(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $hint = $this->worker('hint', $fixture);
        $committing = 0;
        $committed = 0;
        $result = null;
        DB::connection()->getEventDispatcher()->listen(TransactionCommitting::class, function () use ($hint, &$committing): void {
            $committing++;
            $this->start($hint);
            $this->await(fn (): bool => $this->waiting($hint['connection_id'], $this->connectionId(), BillingAwardFence::TABLE, 1), [$hint]);
            // The exact native PRIMARY wait, not elapsed time, proves the insert has reached the held fence.
            $this->assertSame(0, DB::table(BillingSchema::TABLES[3])->count());
        });
        DB::connection()->getEventDispatcher()->listen(TransactionCommitted::class, function () use ($hint, &$committed, &$result): void {
            $committed++;
            $result = $this->finish($hint);
        });
        $this->refused(fn () => (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']), 'committed_receipt_refused');
        $this->assertSame([1, 1], [$committing, $committed]);
        $this->assertSame(false, $result['value']['duplicate']);
        $this->assertSame(1, DB::table(BillingSchema::TABLES[3])->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertIdle();
    }

    public function test_hint_committed_after_repeatable_read_snapshot_is_seen_by_the_owned_awards_current_reads(): void
    {
        $fixture = $this->awardFixture();
        $this->awardTestCapabilities();
        $hint = $this->worker('hint', $fixture);
        $calls = 0;
        DB::connection()->getEventDispatcher()->listen(TransactionBeginning::class, function () use ($hint, &$calls): void {
            $calls++;
            $pdo = DB::connection()->getPdo();
            $this->assertSame('REPEATABLE-READ', $pdo->query('SELECT @@session.transaction_isolation')->fetchColumn());
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM '.BillingSchema::TABLES[3])->fetchColumn());
            $this->start($hint);
            $result = $this->finish($hint);
            $this->assertFalse($result['value']['duplicate']);
            // Keep this ordinary snapshot demonstrably stale before the producer takes its current-reading fence.
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM '.BillingSchema::TABLES[3])->fetchColumn());
        });
        $this->refused(fn () => (new MembershipAwards)->award($fixture['invoice']['id'], $fixture['buyer']['principal'], $fixture['buyer']['user']), 'uncovered_invoice_hint');
        $this->assertSame(1, $calls);
        $this->assertSame(1, DB::table(BillingSchema::TABLES[3])->count());
        $this->assertSame(0, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(0, DB::table(MembershipPeriodRecords::EVENTS)->count());
        $this->assertIdle();
    }

    private function worker(string $operation, array $fixture): array
    {
        $index = count($this->workers);
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('P', 32)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBERSHIP_AWARD_RACE_ONLY' => '1', 'VA_MEMBERSHIP_AWARD_RACE_DIRECTORY' => $this->directory,
            'VA_MEMBERSHIP_AWARD_RACE_WORKER' => (string) $index];
        $input = ['operation' => $operation, 'user_id' => (int) $fixture['buyer']['user']->id, 'invoice_id' => $fixture['invoice']['id'],
            'configuration' => ['production-customer-identity' => config('production-customer-identity'),
                'production-memberships' => config('production-memberships'), 'production-membership-billing' => config('production-membership-billing')]];
        $process = new Process([PHP_BINARY, base_path('tests/Support/membership-award-race-worker.php')], base_path(), $environment,
            // Includes worker startup, the explicit barrier and the owned 60-second admission budget.
            json_encode($input, JSON_THROW_ON_ERROR), 150);
        $process->start();
        $worker = ['process' => $process, 'index' => $index];
        $this->workers[] = $worker;
        $this->await(fn (): bool => is_file($this->directory.'/ready-'.$index), [$worker], 20);
        $ready = json_decode(file_get_contents($this->directory.'/ready-'.$index), true, 8, JSON_THROW_ON_ERROR);
        $worker += $ready;
        $this->workers[$index] = $worker;
        $this->assertNotSame(getmypid(), $worker['pid']);
        $this->assertNotSame($this->connectionId(), $worker['connection_id']);

        return $worker;
    }

    private function start(array $worker): void
    {
        touch($this->directory.'/start-'.$worker['index']);
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), 'Synthetic worker failed: '.$worker['process']->getErrorOutput());
        $result = json_decode($worker['process']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->assertNull($result['error_class'], json_encode(['class' => $result['error_class'], 'reason' => $result['reason']]));
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_transaction']);
        $this->assertSame($worker['connection_id'], $result['connection_id']);

        return $result;
    }

    private function waiting(int $requester, int $blocker, string $table, int $id): bool
    {
        $statement = DB::connection()->getPdo()->prepare(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?
AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' LIMIT 1
SQL);
        $statement->execute([$requester, $blocker, DB::getDatabaseName(), $table]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row !== false && trim($row['row_key'], "'\"") === (string) $id;
    }

    private function await(callable $condition, array $workers, int $seconds = 20): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($workers as $worker) {
                if (! $worker['process']->isRunning()) {
                    $this->fail('Worker ended before barrier: '.$worker['process']->getOutput().$worker['process']->getErrorOutput());
                }
                $worker['process']->checkTimeout();
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);
        $this->fail('Synthetic award worker did not reach the bounded exact record barrier.');
    }

    private function connectionId(): int
    {
        return (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private function assertIdle(): void
    {
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function refused(callable $work, string $reason): void
    {
        try {
            $work();
            $this->fail('Expected the owned award to refuse.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
