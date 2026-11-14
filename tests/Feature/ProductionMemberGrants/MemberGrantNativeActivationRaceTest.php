<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipRecords;
use App\Domain\Memberships\Production\MembershipRedemptionWorkflowLock;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Two real independently confirmed workers, native row waits and one retained synthetic member graph. */
class MemberGrantNativeActivationRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use MemberActivationFixtures;

    private array $workers = [];

    private ?string $directory = null;

    private ?string $receipt = null;

    private array $transcript = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Member activation race requires independent native MySQL processes and observed row waits.');
        }
        $this->memberActivationSetup();
        DB::connection()->getPdo()->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $id = (string) Str::uuid();
        $this->directory = storage_path('framework/testing/member-activation-race-'.$id);
        $evidence = getenv('VA_MEMBER_ACTIVATION_RACE_EVIDENCE_DIRECTORY') ?: storage_path('framework/testing/member-activation-race-evidence');
        foreach ([$this->directory, $evidence] as $directory) {
            (new Filesystem)->ensureDirectoryExists($directory, 0700, true);
        }
        $this->receipt = $evidence.'/'.$this->name().'-'.$id.'.json';
        $this->transcript = ['case' => $this->name(), 'parent_pid' => getmypid(), 'parent_connection_id' => $this->connectionId(),
            'database' => DB::getDatabaseName(), 'mysql_version' => DB::connection()->getPdo()->query('SELECT VERSION()')->fetchColumn(),
            'scope' => 'Actual independent confirmation and activation of synthetic fixtures. No provider, host or production claim.',
            'timing' => 'Distinct synthetic confirmation walls; original monotonic budgets and native process/row waits unchanged.',
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
                        ...($this->transcript['workers'][$worker['index']] ?? []), 'stopped_by_teardown' => $stopped,
                        'exit' => $worker['process']->getExitCode(), 'stdout' => $worker['process']->getOutput(),
                        'stderr' => $worker['process']->getErrorOutput(),
                        'preparation_progress' => is_file($this->directory.'/progress-'.$worker['index'])
                            ? json_decode(file_get_contents($this->directory.'/progress-'.$worker['index']), true, 8, JSON_THROW_ON_ERROR) : null];
                }
                $this->writeReceipt();
                (new Filesystem)->deleteDirectory($this->directory);
            }
            CarbonImmutable::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public function test_independently_confirmed_same_redemption_workers_retain_the_exact_winner_graph(): void
    {
        $this->assertNativeCoordinationOwnership();
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $beforeAudit = $this->ledgerSnapshot($fixture);
        $first = $this->worker($fixture, 0);
        $second = $this->worker($fixture, 1);
        $this->assertCount(3, array_unique([getmypid(), $first['pid'], $second['pid']]));
        $this->assertCount(3, array_unique([$this->connectionId(), $first['connection_id'], $second['connection_id']]));
        $this->assertNotSame($first['authorization_id'], $second['authorization_id']);
        $this->assertNotSame($first['confirmed_at'], $second['confirmed_at']);
        $this->assertNotSame($first['authorization_hash'], $second['authorization_hash']);
        $this->assertSame($before, $this->memberOriginalEffects());
        $userId = (int) $fixture['buyer']['user']->id;
        DB::beginTransaction();
        DB::table('users')->where('id', $userId)->lockForUpdate()->first();
        $parent = $this->connectionId();
        $this->start($first);
        $this->await(fn (): bool => $this->waiting($first['connection_id'], $parent, $userId), [$first, $second]);
        $this->transcript['barriers'][] = ['requester' => $first['connection_id'], 'blocker' => $parent,
            'table' => 'users', 'primary_key' => $userId, 'observed_ns' => hrtime(true)];
        $lockName = MembershipRedemptionWorkflowLock::name(DB::getDatabaseName(), $fixture['reservation']['redemption_id']);
        $this->assertSame($first['connection_id'], $this->lockOwner($lockName));
        $this->start($second);
        $this->await(fn (): bool => $this->coordinationWaiting($second['connection_id'], $first['connection_id'], $lockName), [$first, $second]);
        $this->transcript['barriers'][] = ['requester' => $second['connection_id'], 'blocker' => $first['connection_id'],
            'lock_type' => 'USER LEVEL LOCK', 'lock_name' => $lockName, 'observed_ns' => hrtime(true)];
        $this->writeReceipt();
        DB::commit();
        $this->transcript['parent_released_ns'] = hrtime(true);
        $results = [$this->finish($first), $this->finish($second)];
        // Preserve observed durable effects even when the strict successful-writer oracle fails.
        // This is diagnostic metadata, not an assertion of a complete or authenticated graph.
        $observed = $this->memberOriginalEffects();
        $this->transcript['post_worker_observation'] = ['retained_rows_sha256' => CanonicalJson::hash($observed),
            'row_counts' => array_map(count(...), $observed),
            'event_kinds' => DB::table(MembershipPeriodRecords::EVENTS)->orderBy('sequence')->pluck('kind')->all()];
        $this->writeReceipt();
        $this->assertNull($this->lockOwner($lockName), 'Both real workers must retire their exact scheduling lock.');
        $successes = array_values(array_filter($results, static fn (array $result): bool => $result['errors'] === []));
        $this->assertNotEmpty($successes, 'At least one actual worker must return its committed activation receipt.');
        $expected = $successes[0]['value'];
        $this->assertSame('activated', $expected['status']);
        foreach ($results as $index => $result) {
            if ($result['errors'] === []) {
                $this->assertSame($expected, $result['value']);

                continue;
            }
            // A bounded loser is retained as that outcome, never relabelled successful concurrent replay.
            $this->assertNull($result['value']);
            $this->assertContains('source_deadline', array_column($result['errors'], 'reason'), json_encode($result['errors']));
            $this->assertGreaterThanOrEqual(60_000_000_000, $result['finished_ns'] - $result['entered_ns']);
            $this->transcript['bounded_loser'] = ['worker' => $index, 'errors' => $result['errors'],
                'elapsed_ns' => $result['finished_ns'] - $result['entered_ns']];
        }
        $retained = $this->memberOriginalEffects();
        foreach (['production_member_origins', 'production_member_artifacts', 'production_member_activations'] as $table) {
            $this->assertCount(1, $retained[$table]);
        }
        $this->assertSame(['award', 'reserve', 'consume'], DB::table(MembershipPeriodRecords::EVENTS)->orderBy('sequence')->pluck('kind')->all());
        $storedPlan = DB::table(MembershipRecords::TABLE)->where('id', $fixture['plan']['id'])->first();
        $this->assertNotNull($storedPlan);
        $policy = MembershipPolicyVersions::document((array) $storedPlan)->binding($fixture['plan']['id']);
        $origin = MemberActivationRecords::readOrigin($retained['production_member_origins'][0], $policy);
        $activation = MemberActivationRecords::readActivation($retained['production_member_activations'][0]);
        $winnerHash = CanonicalJson::hash($origin['authorization']);
        $winningWorkers = array_values(array_filter([$first, $second], static fn (array $worker): bool => $worker['authorization_hash'] === $winnerHash));
        $this->assertCount(1, $winningWorkers);
        $winner = $winningWorkers[0];
        $this->assertSame([], $results[$winner['index']]['errors'], 'The actual graph writer must return its receipt; a bounded refusal may only describe the other worker.');
        $this->assertSame($winner['authorization_id'], $origin['authorization']['authorization_id']);
        $this->assertSame($winner['confirmed_at'], $origin['authorization']['confirmed_at']);
        $this->assertSame($winnerHash, $activation['authorization_document_hash']);
        $this->assertSame(CanonicalJson::hash($origin['readiness_commitment']), $activation['readiness_receipt_hash']);
        $this->assertSame(CanonicalJson::hash($activation), $expected['activationHash']);
        $this->assertSame($fixture['reservation']['redemption_id'], $expected['originId']);
        $audit = $this->ledgerSnapshot($fixture);
        $this->assertSame(['available' => $beforeAudit['balances']['available'], 'reserved' => 0,
            'consumed' => $fixture['reservation']['credit_amount'], 'expired' => 0], $audit['balances']);
        $this->transcript['winner'] = ['worker' => $winner['index'], 'authorization_id' => $winner['authorization_id'],
            'confirmed_at' => $winner['confirmed_at'], 'authorization_hash' => $winnerHash,
            'readiness_hash' => CanonicalJson::hash($origin['readiness_commitment']), 'activation_hash' => CanonicalJson::hash($activation),
            'receipt' => $expected, 'retained_rows_sha256' => CanonicalJson::hash($retained), 'row_counts' => array_map(count(...), $retained)];
        $paths = $this->memberActivationFiles($expected['redemptionId']);
        $this->assertCount(1, $paths);
        $bytesHash = hash_file('sha256', $paths[0]);
        $this->assertSame($retained['production_member_artifacts'][0]['sha256'], $bytesHash);
        // Separate explicit recovery after both workers end; preserves the winner's actual A/R/D.
        $this->assertEquals($expected, get_object_vars($this->readMemberActivation($fixture)));
        $this->assertSame($retained, $this->memberOriginalEffects());
        $this->assertSame($bytesHash, hash_file('sha256', $paths[0]));
        $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->transcript['explicit_recovery_preserved_graph'] = true;
        $this->writeReceipt();
    }

    private function worker(array $fixture, int $index): array
    {
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('P', 32)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBER_ACTIVATION_RACE_ONLY' => '1', 'VA_MEMBER_ACTIVATION_RACE_DIRECTORY' => $this->directory,
            'VA_MEMBER_ACTIVATION_RACE_WORKER' => (string) $index];
        $input = ['user_id' => (int) $fixture['buyer']['user']->id, 'definition_id' => $fixture['member_authored']->definitionId,
            'redemption_id' => $fixture['reservation']['redemption_id'], 'at' => CarbonImmutable::now('UTC')->addSeconds($index)->format('Y-m-d H:i:s.u'),
            'configuration' => ['production-customer-identity' => config('production-customer-identity'),
                'production-memberships' => config('production-memberships'), 'production-membership-billing' => config('production-membership-billing'),
                'member-grants' => config('member-grants'), 'filesystems.disks.local' => config('filesystems.disks.local'), 'media' => config('media')]];
        $process = new Process([PHP_BINARY, base_path('tests/Support/member-activation-race-worker.php')], base_path(), $environment,
            json_encode($input, JSON_THROW_ON_ERROR), 600);
        $process->start();
        $worker = ['process' => $process, 'index' => $index];
        $this->workers[] = $worker;
        $this->transcript['workers'][$index] = ['command' => $process->getCommandLine(), 'spawned_pid' => $process->getPid(), 'at' => $input['at']];
        $this->await(fn (): bool => is_file($this->directory.'/ready-'.$index), [$worker], 150);
        $ready = json_decode(file_get_contents($this->directory.'/ready-'.$index), true, 8, JSON_THROW_ON_ERROR);
        $worker += $ready;
        $this->workers[$index] = $worker;
        $this->transcript['workers'][$index]['ready'] = $ready;
        $this->writeReceipt();
        $this->assertSame(0, $ready['transaction_level']);
        $this->assertFalse($ready['pdo_transaction']);

        return $worker;
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
        $this->assertSame($worker['connection_id'], $result['connection_id']);
        $this->assertSame($worker['pid'], $result['pid']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_transaction']);

        return $result;
    }

    private function start(array $worker): void
    {
        $this->transcript['workers'][$worker['index']]['released_ns'] = hrtime(true);
        touch($this->directory.'/start-'.$worker['index']);
    }

    private function waiting(int $requester, int $blocker, int $userId): bool
    {
        $statement = DB::connection()->getPdo()->prepare(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?
AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'users' AND requested.INDEX_NAME = 'PRIMARY'
AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' LIMIT 1
SQL);
        $statement->execute([$requester, $blocker, DB::getDatabaseName()]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        return $row !== false && trim($row['row_key'], "'\"") === (string) $userId;
    }

    private function lockOwner(string $name): ?int
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT IS_USED_LOCK(?)');
        $statement->execute([$name]);
        $owner = $statement->fetchColumn();

        return $owner === null ? null : (int) $owner;
    }

    private function assertNativeCoordinationOwnership(): void
    {
        $pdo = DB::connection()->getPdo();
        $redemptionId = (string) Str::uuid();
        $name = MembershipRedemptionWorkflowLock::name(DB::getDatabaseName(), $redemptionId);
        $get = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $refusal = static function (callable $operation): ?string {
            try {
                $operation();
            } catch (MembershipException $error) {
                return $error->reason;
            }

            return null;
        };
        // A pre-existing same-session acquisition must remain the caller's lock.
        $get->execute([$name]);
        $this->assertSame(1, (int) $get->fetchColumn());
        try {
            $reason = $refusal(fn () => MembershipRedemptionWorkflowLock::acquire($pdo, $redemptionId, hrtime(true) + 60_000_000_000));
            $this->assertSame('coordination_refused', $reason);
            $this->assertSame($this->connectionId(), $this->lockOwner($name));
        } finally {
            $release->execute([$name]);
        }
        $lock = MembershipRedemptionWorkflowLock::acquire($pdo, $redemptionId, hrtime(true) + 60_000_000_000);
        $this->assertNotNull($lock);
        $lock->finish(hrtime(true) + 60_000_000_000);
        $this->assertNull($this->lockOwner($name));
        $reason = $refusal(fn () => $lock->finish(hrtime(true) + 60_000_000_000));
        $this->assertSame('coordination_refused', $reason);
        $lock->closeAfterRefusal();
        // Native error cleanup releases only scheduling state; it never ends a foreign transaction.
        $lock = MembershipRedemptionWorkflowLock::acquire($pdo, $redemptionId, hrtime(true) + 60_000_000_000);
        $pdo->beginTransaction();
        try {
            $lock->closeAfterRefusal();
            $this->assertTrue($pdo->inTransaction());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull($this->lockOwner($name));
        } finally {
            $pdo->rollBack();
        }
        // An injected recursive acquisition cannot be silently decremented twice by cleanup.
        $lock = MembershipRedemptionWorkflowLock::acquire($pdo, $redemptionId, hrtime(true) + 60_000_000_000);
        $get->execute([$name]);
        $this->assertSame(1, (int) $get->fetchColumn());
        try {
            $reason = $refusal(fn () => $lock->finish(hrtime(true) + 60_000_000_000));
            $this->assertSame('coordination_refused', $reason);
            $this->assertSame($this->connectionId(), $this->lockOwner($name));
            $reason = $refusal(fn () => $lock->closeAfterRefusal());
            $this->assertSame('coordination_refused', $reason);
            $this->assertSame($this->connectionId(), $this->lockOwner($name));
        } finally {
            $release->execute([$name]);
        }
        $this->assertNull($this->lockOwner($name));
    }

    private function coordinationWaiting(int $requester, int $blocker, string $name): bool
    {
        $statement = DB::connection()->getPdo()->prepare(<<<'SQL'
SELECT pending.OBJECT_NAME FROM performance_schema.metadata_locks pending
JOIN performance_schema.threads requester ON requester.THREAD_ID = pending.OWNER_THREAD_ID
JOIN performance_schema.metadata_locks granted ON granted.OBJECT_TYPE = pending.OBJECT_TYPE AND granted.OBJECT_NAME = pending.OBJECT_NAME
JOIN performance_schema.threads blocker ON blocker.THREAD_ID = granted.OWNER_THREAD_ID
WHERE pending.OBJECT_TYPE = 'USER LEVEL LOCK' AND pending.OBJECT_NAME = ? AND pending.OBJECT_SCHEMA IS NULL
AND pending.LOCK_STATUS = 'PENDING' AND granted.LOCK_STATUS = 'GRANTED'
AND requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ? LIMIT 1
SQL);
        $statement->execute([$name, $requester, $blocker]);

        return $statement->fetchColumn() === $name;
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
                    $this->fail('Activation worker ended before barrier: '.$worker['process']->getOutput().$worker['process']->getErrorOutput());
                }
                $worker['process']->checkTimeout();
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);
        $this->fail('Activation worker did not reach the bounded native barrier.');
    }

    private function connectionId(): int
    {
        return (int) DB::connection()->getPdo()->query('SELECT CONNECTION_ID()')->fetchColumn();
    }

    private function writeReceipt(): void
    {
        if ($this->receipt !== null) {
            file_put_contents($this->receipt, json_encode($this->transcript, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        }
    }
}
