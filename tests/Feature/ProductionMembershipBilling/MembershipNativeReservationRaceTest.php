<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAwardFence;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipReservationFixtures;
use Tests\TestCase;

/** Real process/connection races over synthetic retained facts; frozen wall equality is not physical timing proof. */
class MembershipNativeReservationRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MembershipReservationFixtures;

    private array $workers = [];

    private ?string $directory = null;

    private ?string $receipt = null;

    private array $transcript = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Reservation serialization requires independent native MySQL processes and record waits.');
        }
        $this->reservationSetup();
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $id = (string) Str::uuid();
        $this->directory = storage_path('framework/testing/membership-reservation-'.$id);
        $evidence = getenv('VA_MEMBERSHIP_RESERVATION_RACE_EVIDENCE_DIRECTORY') ?: storage_path('framework/testing/membership-reservation-evidence');
        foreach ([$this->directory, $evidence] as $directory) {
            (new Filesystem)->ensureDirectoryExists($directory, 0700, true);
        }
        $this->receipt = $evidence.'/'.$this->name().'-'.$id.'.json';
        $this->transcript = ['case' => $this->name(), 'parent_pid' => getmypid(), 'parent_connection_id' => $this->connectionId(),
            'database' => DB::getDatabaseName(), 'mysql_version' => DB::connection()->getPdo()->query('SELECT VERSION()')->fetchColumn(),
            'timing_scope' => 'Explicit synthetic Carbon wall time; real hrtime, process scheduling, native row waits and admission bounds.',
            'authority_scope' => 'Real retained invoice, policy and eligible-license producers; unused fixture-only C4 grant gate; no provider/host claim.',
            'barriers' => [], 'workers' => []];
    }

    protected function tearDown(): void
    {
        try {
            if ($this->directory !== null) {
                if (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ($this->workers as $worker) {
                    $stopped = $worker['process']->isRunning();
                    if ($stopped) {
                        $worker['process']->stop(1);
                    }
                    $this->transcript['workers'][$worker['index']] = [
                        ...($this->transcript['workers'][$worker['index']] ?? []),
                        'stopped_by_teardown' => $stopped, 'exit' => $worker['process']->getExitCode(),
                        'stdout' => $worker['process']->getOutput(), 'stderr' => $worker['process']->getErrorOutput()];
                }
                $this->writeReceipt();
                (new Filesystem)->deleteDirectory($this->directory);
            }
            CarbonImmutable::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public function test_different_request_keys_cannot_spend_the_same_last_credit(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['credit_allowance' => 1]);
        [$first, $second] = $this->race($fixture, ['operation' => 'reserve', 'request_key' => 'native-first-key'],
            ['operation' => 'reserve', 'request_key' => 'native-second-key']);
        $this->success($first, 'reserved');
        $this->domainRefusal($second, 'insufficient_credit');
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 0, 'expired' => 0], $audit['balances']);
        $this->assertSame([$first['value']['redemption_id']], array_keys($audit['reservations']));
        $this->assertSame($first['value']['reservation_event_id'], $audit['reservations'][$first['value']['redemption_id']]['reserve']['id']);
        $this->assertLedgerShape(['award', 'reserve'], 1);
    }

    public function test_same_key_and_intent_return_the_same_retained_reservation_and_event(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['credit_allowance' => 1]);
        [$first, $second] = $this->race($fixture, ['operation' => 'reserve', 'request_key' => 'native-same-key'],
            ['operation' => 'reserve', 'request_key' => 'native-same-key']);
        $this->success($first, 'reserved');
        $this->sameReceiptOrElapsedAdmission($first, $second, 'reserved');
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 0, 'reserved' => 1, 'consumed' => 0, 'expired' => 0], $audit['balances']);
        $this->assertSame([$first['value']['redemption_id']], array_keys($audit['reservations']));
        $this->assertSame($first['value']['reservation_event_id'], $audit['reservations'][$first['value']['redemption_id']]['reserve']['id']);
        $this->assertSame($first['value']['honor_deadline'], $audit['reservations'][$first['value']['redemption_id']]['record']['honor_deadline']);
        $this->assertLedgerShape(['award', 'reserve'], 1);
        $this->freshRetryPreservesLedger($fixture, ['operation' => 'reserve', 'request_key' => 'native-same-key'], $first['value'], $audit);
    }

    public function test_duplicate_release_at_honor_equality_creates_one_terminal_without_inflation(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['credit_allowance' => 1]);
        $saved = $this->reserveFixture($fixture);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $arguments = ['operation' => 'release', 'redemption_id' => $saved['redemption_id']];
        [$first, $second] = $this->race($fixture, $arguments, $arguments);
        $this->success($first, 'released');
        $this->assertSame($saved['period_id'], $first['value']['period_id']);
        $this->assertSame($saved['redemption_id'], $first['value']['redemption_id']);
        $this->assertSame($saved['reservation_event_id'], $first['value']['reservation_event_id']);
        $this->sameReceiptOrElapsedAdmission($first, $second, 'released');
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 1, 'reserved' => 0, 'consumed' => 0, 'expired' => 0], $audit['balances']);
        $this->assertSame($first['value']['release_event_id'], $audit['reservations'][$saved['redemption_id']]['terminal']['id']);
        $this->assertSame($saved['honor_deadline'], $audit['reservations'][$saved['redemption_id']]['record']['honor_deadline']);
        $this->assertLedgerShape(['award', 'reserve', 'release'], 1);
        $this->freshRetryPreservesLedger($fixture, $arguments, $first['value'], $audit);
        $this->refused(fn () => $this->reserveFixture($fixture), 'reservation_terminal');
    }

    public function test_release_after_period_expiry_moves_credit_to_expired_and_cannot_resurrect_it(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['credit_allowance' => 1, 'award_terms' => [
            'expiry' => ['mode' => 'period_end', 'seconds' => 0], 'late_payment' => ['mode' => 'allow', 'seconds' => 0]]],
            honorOverrides: ['duration_seconds' => 120, 'expiry_mode' => 'allow_beyond_credit_expiry']);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(BillingStripeFixtures::PERIOD_END - 60));
        $this->refreshReservationObservation($fixture);
        $saved = $this->reserveFixture($fixture);
        $this->assertSame(BillingStripeFixtures::PERIOD_END + 60, CarbonImmutable::parse($saved['honor_deadline'], 'UTC')->timestamp);
        CarbonImmutable::setTestNow(CarbonImmutable::parse($saved['honor_deadline'], 'UTC'));
        $this->refreshReservationObservation($fixture);
        $arguments = ['operation' => 'release', 'redemption_id' => $saved['redemption_id']];
        [$first, $second] = $this->race($fixture, $arguments, $arguments);
        $this->success($first, 'released');
        $this->assertSame($saved['period_id'], $first['value']['period_id']);
        $this->assertSame($saved['redemption_id'], $first['value']['redemption_id']);
        $this->assertSame($saved['reservation_event_id'], $first['value']['reservation_event_id']);
        $this->sameReceiptOrElapsedAdmission($first, $second, 'released');
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 1], $audit['balances']);
        $this->assertSame($first['value']['release_event_id'], $audit['reservations'][$saved['redemption_id']]['terminal']['id']);
        $this->assertSame($saved['honor_deadline'], $audit['reservations'][$saved['redemption_id']]['record']['honor_deadline']);
        $this->assertLedgerShape(['award', 'reserve', 'release'], 1);
        $this->freshRetryPreservesLedger($fixture, $arguments, $first['value'], $audit);
        $this->refused(fn () => $this->reserveFixture($fixture, 'new-after-expiry'), 'credit_expired');
        $this->assertSame($audit['balances'], $this->ledgerSnapshot($fixture)['balances']);
    }

    public function test_available_expiry_and_reserve_at_synthetic_equality_conserve_the_allowance(): void
    {
        $fixture = $this->reservationFixture(policyOverrides: ['credit_allowance' => 1, 'award_terms' => [
            'expiry' => ['mode' => 'period_end', 'seconds' => 0], 'late_payment' => ['mode' => 'allow', 'seconds' => 0]]]);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(BillingStripeFixtures::PERIOD_END));
        $this->refreshReservationObservation($fixture);
        [$first, $second] = $this->race($fixture, ['operation' => 'expire'],
            ['operation' => 'reserve', 'request_key' => 'native-at-expiry']);
        $this->success($first, 'available_credit_expired');
        $this->domainRefusal($second, 'credit_expired');
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => 0, 'reserved' => 0, 'consumed' => 0, 'expired' => 1], $audit['balances']);
        $this->assertSame([], $audit['reservations']);
        $this->assertSame($first['value']['expiry_event_id'], $audit['tail']['id']);
        $this->assertLedgerShape(['award', 'expire'], 0);
    }

    private function race(array $fixture, array $one, array $two): array
    {
        $first = $this->worker($fixture, $one);
        $second = $this->worker($fixture, $two);
        $parent = $this->connectionId();
        $this->assertCount(3, array_unique([$parent, $first['connection_id'], $second['connection_id']]));
        $this->assertCount(3, array_unique([getmypid(), $first['pid'], $second['pid']]));
        DB::beginTransaction();
        (new BillingAwardFence)->lock(DB::connection()->getPdo());
        $this->start($first);
        $this->await(fn (): bool => $this->waiting($first['connection_id'], $parent, BillingAwardFence::TABLE, 1), [$first]);
        $this->transcript['barriers'][] = ['requester' => $first['connection_id'], 'blocker' => $parent,
            'table' => BillingAwardFence::TABLE, 'primary_key' => 1, 'observed_ns' => hrtime(true)];
        $this->start($second);
        $userId = (int) $fixture['buyer']['user']->id;
        $this->await(fn (): bool => $this->waiting($second['connection_id'], $first['connection_id'], 'users', $userId), [$first, $second]);
        $this->transcript['barriers'][] = ['requester' => $second['connection_id'], 'blocker' => $first['connection_id'],
            'table' => 'users', 'primary_key' => $userId, 'observed_ns' => hrtime(true)];
        $this->writeReceipt();
        // Identity/fixed-fence locks order these operations before their period lock; never attribute this solely to the period.
        DB::commit();
        $results = [$this->finish($first), $this->finish($second)];
        $this->assertCount(3, array_unique([getmypid(), $results[0]['pid'], $results[1]['pid']]));

        return $results;
    }

    private function worker(array $fixture, array $arguments): array
    {
        $index = count($this->workers);
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('P', 32)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBERSHIP_RESERVATION_RACE_ONLY' => '1', 'VA_MEMBERSHIP_RESERVATION_RACE_DIRECTORY' => $this->directory,
            'VA_MEMBERSHIP_RESERVATION_RACE_WORKER' => (string) $index];
        $input = [...$arguments, 'user_id' => (int) $fixture['buyer']['user']->id, 'invoice_id' => $fixture['invoice']['id'],
            'selection' => $fixture['selection'], 'expected_revision' => $fixture['expected_revision'],
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'configuration' => ['production-customer-identity' => config('production-customer-identity'),
                'production-memberships' => config('production-memberships'), 'production-membership-billing' => config('production-membership-billing'),
                'filesystems.disks.local' => config('filesystems.disks.local'), 'media' => config('media')]];
        $process = new Process([PHP_BINARY, base_path('tests/Support/membership-reservation-race-worker.php')], base_path(), $environment,
            json_encode($input, JSON_THROW_ON_ERROR), 150);
        $process->start();
        $worker = ['process' => $process, 'index' => $index];
        $this->workers[] = $worker;
        $this->transcript['workers'][$index] = ['command' => $process->getCommandLine(), 'spawned_pid' => $process->getPid(),
            'operation' => $arguments['operation'], 'request_key' => $arguments['request_key'] ?? null,
            'redemption_id' => $arguments['redemption_id'] ?? null, 'at' => $input['at']];
        $this->await(fn (): bool => is_file($this->directory.'/ready-'.$index), [$worker]);
        $ready = json_decode(file_get_contents($this->directory.'/ready-'.$index), true, 8, JSON_THROW_ON_ERROR);
        $worker += $ready;
        $this->workers[$index] = $worker;
        $this->transcript['workers'][$index]['ready'] = $ready;
        $this->writeReceipt();
        $this->assertNotSame(getmypid(), $worker['pid']);
        $this->assertNotSame($this->connectionId(), $worker['connection_id']);

        return $worker;
    }

    private function start(array $worker): void
    {
        $this->transcript['workers'][$worker['index']]['released_ns'] = hrtime(true);
        touch($this->directory.'/start-'.$worker['index']);
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        $this->transcript['workers'][$worker['index']]['exit'] = $worker['process']->getExitCode();
        $this->transcript['workers'][$worker['index']]['stdout'] = $worker['process']->getOutput();
        $this->transcript['workers'][$worker['index']]['stderr'] = $worker['process']->getErrorOutput();
        $this->writeReceipt();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getOutput().$worker['process']->getErrorOutput());
        $result = json_decode($worker['process']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->transcript['workers'][$worker['index']]['result'] = $result;
        $this->writeReceipt();
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_transaction']);
        $this->assertSame($worker['connection_id'], $result['connection_id']);
        $this->assertSame($worker['pid'], $result['pid']);

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
                    $this->fail('Reservation worker ended before the barrier: '.$worker['process']->getOutput().$worker['process']->getErrorOutput());
                }
                $worker['process']->checkTimeout();
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);
        $this->fail('Reservation worker did not reach the bounded exact native record barrier.');
    }

    private function connectionId(): int
    {
        return (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private function assertLedgerShape(array $kinds, int $redemptions): void
    {
        $this->assertSame($kinds, DB::table(MembershipPeriodRecords::EVENTS)->orderBy('sequence')->pluck('kind')->all());
        $this->assertSame($redemptions, DB::table(MembershipReservationRecords::TABLE)->count());
        $this->assertSame(1, DB::table(MembershipPeriodRecords::PERIODS)->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function sameReceiptOrElapsedAdmission(array $first, array $second, string $status): void
    {
        $elapsed = $second['finished_ns'] - $second['entered_ns'];
        $this->transcript['concurrent_retry'] = ['outcome' => $second['error_class'] === null ? 'same_receipt' : $second['reason'],
            'elapsed_ns' => $elapsed, 'original_admission_budget_ns' => 60_000_000_000];
        $this->writeReceipt();
        if ($second['error_class'] === null) {
            $this->success($second, $status);
            $this->assertSame($first['value'], $second['value']);
        } else {
            // The original budget includes real lock waits. Only elapsed precommit admission may refuse.
            $this->domainRefusal($second, 'operation_deadline');
            $this->assertNull($second['value']);
            $this->assertGreaterThanOrEqual(60_000_000_000, $elapsed);
            $this->assertSame(0, $second['transaction_level']);
            $this->assertFalse($second['pdo_transaction']);
        }
    }

    private function freshRetryPreservesLedger(array $fixture, array $arguments, array $expected, array $beforeAudit): void
    {
        // All workers have ended and the real full-chain audit/cardinality checks ran before this separate invocation.
        $before = $this->retainedLedgerRows();
        $this->transcript['before_fresh_retry'] = $this->auditedSummary($beforeAudit, $before);
        $this->writeReceipt();
        $entered = hrtime(true);
        $operations = new MembershipReservationOperations;
        $this->transcript['fresh_retry'] = ['scope' => 'Separate explicitly invoked parent operation after workers and full audit; no automatic operation retry.',
            'operation' => $arguments['operation'], 'entered_ns' => $entered];
        try {
            $value = match ($arguments['operation']) {
                'reserve' => $operations->reserve($fixture['invoice']['id'], $fixture['selection'], $fixture['expected_revision'],
                    $arguments['request_key'], $fixture['buyer']['principal'], $fixture['buyer']['user']),
                'release' => $operations->release($fixture['invoice']['id'], $arguments['redemption_id'],
                    $fixture['buyer']['principal'], $fixture['buyer']['user']),
            };
            $this->transcript['fresh_retry']['receipt'] = $value;
        } catch (\Throwable $error) {
            $this->transcript['fresh_retry']['error_class'] = get_class($error);
            $this->transcript['fresh_retry']['reason'] = property_exists($error, 'reason') ? $error->reason : null;
            throw $error;
        } finally {
            $this->transcript['fresh_retry']['finished_ns'] = hrtime(true);
            $this->transcript['fresh_retry']['transaction_level'] = DB::transactionLevel();
            $this->transcript['fresh_retry']['pdo_transaction'] = DB::connection()->getPdo()->inTransaction();
            $this->writeReceipt();
        }
        $this->assertSame($expected, $value);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $afterAudit = $this->ledgerSnapshot($fixture);
        $after = $this->retainedLedgerRows();
        $this->transcript['after_fresh_retry'] = $this->auditedSummary($afterAudit, $after);
        $this->writeReceipt();
        $this->assertSame($beforeAudit, $afterAudit);
        $this->assertSame($before, $after);
    }

    private function retainedLedgerRows(): array
    {
        $rows = [];
        foreach ([MembershipPeriodRecords::PERIODS, MembershipPeriodRecords::EVENTS, MembershipReservationRecords::TABLE] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function auditedSummary(array $audit, array $rows): array
    {
        return ['period_id' => $audit['period']['id'], 'tail_event_id' => $audit['tail']['id'],
            'balances' => $audit['balances'], 'redemption_ids' => array_keys($audit['reservations']),
            'row_counts' => array_map(count(...), $rows), 'audit_sha256' => CanonicalJson::hash($audit),
            'retained_rows_sha256' => CanonicalJson::hash($rows)];
    }

    private function success(array $result, string $status): void
    {
        $this->assertNull($result['error_class'], json_encode(['class' => $result['error_class'], 'reason' => $result['reason']]));
        $this->assertSame($status, $result['value']['status']);
    }

    private function domainRefusal(array $result, string $reason): void
    {
        $this->assertSame(MembershipException::class, $result['error_class']);
        $this->assertSame($reason, $result['reason']);
        $this->assertNull($result['value']);
    }

    private function refused(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected the retained lifecycle boundary to refuse.');
        } catch (MembershipException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function writeReceipt(): void
    {
        if ($this->receipt !== null) {
            file_put_contents($this->receipt, json_encode($this->transcript, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }
}
