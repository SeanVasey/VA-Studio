<?php

namespace Tests\Feature\ProductionMemberGrants;

use App\Domain\Grants\Member\MemberActivationRecords;
use App\Domain\Memberships\Production\MembershipPeriodRecords;
use App\Domain\Memberships\Production\MembershipPolicyVersions;
use App\Domain\Memberships\Production\MembershipReservationRecords;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MemberActivationFixtures;
use Tests\TestCase;

/** Actual terminal orders at disclosed synthetic walls; original monotonic caps remain unchanged. */
class MemberGrantNativeActivationReleaseRaceTest extends TestCase
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
            $this->markTestSkipped('Requires independent MySQL terminal writers and observed native row waits.');
        }
        $this->memberActivationSetup();
        $id = (string) Str::uuid();
        $this->directory = storage_path('framework/testing/member-activation-release-race-'.$id);
        $evidence = getenv('VA_MEMBER_ACTIVATION_RELEASE_RACE_EVIDENCE_DIRECTORY')
            ?: storage_path('framework/testing/member-activation-release-race-evidence');
        foreach ([$this->directory, $evidence] as $directory) {
            (new Filesystem)->ensureDirectoryExists($directory, 0700, true);
        }
        $this->receipt = $evidence.'/'.$this->name().'-'.$id.'.json';
        $this->transcript = ['case' => $this->name(), 'parent_pid' => getmypid(),
            'parent_connection_id' => $this->connectionId(), 'database' => DB::getDatabaseName(),
            'mysql_version' => DB::connection()->getPdo()->query('SELECT VERSION()')->fetchColumn(),
            'scope' => 'Synthetic actual producers, normal committing delegates and native row waits; no host/provider claim.',
            'timing_limit' => 'Consume-wins uses different process-local business walls. Exact-honor activation after release refuses a prior terminal; the separate portable test isolates equality without a terminal.',
            'workers' => []];
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $role => $worker) {
                $stopped = $worker['process']->isRunning();
                if ($stopped) {
                    $worker['process']->stop(1);
                }
                $this->transcript['workers'][$role] = [...($this->transcript['workers'][$role] ?? []),
                    'stopped_by_teardown' => $stopped, 'exit' => $worker['process']->getExitCode(),
                    'stdout' => $worker['process']->getOutput(), 'stderr' => $worker['process']->getErrorOutput()];
            }
            $this->writeReceipt();
            if ($this->directory !== null) {
                (new Filesystem)->deleteDirectory($this->directory);
            }
            CarbonImmutable::setTestNow();
        } finally {
            parent::tearDown();
        }
    }

    public static function terminalOrders(): array
    {
        return ['consume before honor wins' => ['activate'], 'release at honor wins' => ['release']];
    }

    #[DataProvider('terminalOrders')]
    public function test_actual_consume_and_release_serialize_at_the_authored_honor_boundary(string $winnerRole): void
    {
        $fixture = $this->memberActivationFixture();
        $before = $this->memberOriginalEffects();
        $beforeAudit = $this->ledgerSnapshot($fixture);
        $walls = ['confirm' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'activate' => $winnerRole === 'activate' ? CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u')
                : CarbonImmutable::parse($fixture['reservation']['honor_deadline'], 'UTC')->format('Y-m-d H:i:s.u'),
            'release' => CarbonImmutable::parse($fixture['reservation']['honor_deadline'], 'UTC')->format('Y-m-d H:i:s.u')];
        $this->transcript['walls'] = $walls;
        $this->transcript['scheduled_winner'] = $winnerRole;
        $activate = $this->worker($fixture, 'activate', $winnerRole === 'activate', $walls);
        $release = $this->worker($fixture, 'release', $winnerRole === 'release', $walls);
        $this->assertCount(3, array_unique([getmypid(), $activate['pid'], $release['pid']]));
        $this->assertCount(3, array_unique([$this->connectionId(), $activate['connection_id'], $release['connection_id']]));
        $this->assertNotNull($activate['authorization']);
        $this->assertNull($release['authorization']);
        $this->assertSame($before, $this->memberOriginalEffects());
        $loserRole = $winnerRole === 'activate' ? 'release' : 'activate';
        $winner = $this->workers[$winnerRole];
        $loser = $this->workers[$loserRole];
        $this->start($winner);
        $this->await(fn (): bool => is_file($this->directory.'/held-'.$winnerRole), 120);
        $held = json_decode(file_get_contents($this->directory.'/held-'.$winnerRole), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $held['transaction_level']);
        $this->assertTrue($held['pdo_transaction']);
        $this->start($loser);
        $this->await(fn (): bool => $this->waiting($loser['connection_id'], $winner['connection_id'], (int) $fixture['buyer']['user']->id), 18);
        $this->transcript['observed_wait'] = ['requester' => $loser['connection_id'], 'blocker' => $winner['connection_id'],
            'table' => 'users', 'primary_key' => (int) $fixture['buyer']['user']->id, 'observed_ns' => hrtime(true), 'held' => $held];
        $this->writeReceipt();
        touch($this->directory.'/commit-'.$winnerRole);
        $results = [$winnerRole => $this->finish($winner), $loserRole => $this->finish($loser)];
        $this->assertSame([], $results[$winnerRole]['errors']);
        $this->assertSame($winnerRole === 'activate' ? 'activated' : 'released', $results[$winnerRole]['value']['status']);
        $this->assertNull($results[$loserRole]['value']);
        $this->assertContains('reservation_terminal', array_column($results[$loserRole]['errors'], 'reason'));
        $this->assertNotNull($results[$winnerRole]['held']);
        $this->assertNull($results[$loserRole]['held']);
        $this->assertTerminalGraph($fixture, $before, $beforeAudit, $results[$winnerRole]['value'], $activate, $winnerRole);
        $this->transcript['one_terminal_and_conservation_verified'] = true;
        $this->writeReceipt();
    }

    private function assertTerminalGraph(array $fixture, array $before, array $beforeAudit, array $value, array $activate, string $winner): void
    {
        $after = $this->memberOriginalEffects();
        $kind = $winner === 'activate' ? 'consume' : 'release';
        $this->assertSame(['award', 'reserve', $kind], DB::table(MembershipPeriodRecords::EVENTS)->orderBy('sequence')->pluck('kind')->all());
        $terminals = DB::table(MembershipPeriodRecords::EVENTS)->where('redemption_id', $fixture['reservation']['redemption_id'])
            ->whereIn('kind', ['consume', 'release'])->get();
        $this->assertCount(1, $terminals);
        $this->assertSame($kind, $terminals[0]->kind);
        $this->assertSame($terminals[0]->id, $value[$winner === 'activate' ? 'consumeEventId' : 'release_event_id']);
        foreach (['production_member_origins', 'production_member_artifacts', 'production_member_activations'] as $table) {
            $this->assertCount($winner === 'activate' ? 1 : 0, $after[$table]);
        }
        foreach (['production_member_profiles', 'production_member_definitions', MembershipPeriodRecords::PERIODS,
            MembershipReservationRecords::TABLE] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
        $this->refreshReservationObservation($fixture);
        $audit = $this->ledgerSnapshot($fixture);
        $amount = $fixture['reservation']['credit_amount'];
        $this->assertSame(['available' => $beforeAudit['balances']['available'] + ($winner === 'release' ? $amount : 0),
            'reserved' => 0, 'consumed' => $winner === 'activate' ? $amount : 0, 'expired' => 0], $audit['balances']);
        $this->assertSame(array_sum($beforeAudit['balances']), array_sum($audit['balances']));
        $paths = $this->memberActivationFiles($fixture['reservation']['redemption_id']);
        if ($winner === 'activate') {
            $policy = MembershipPolicyVersions::document($fixture['plan'])->binding($fixture['plan']['id']);
            $origin = MemberActivationRecords::readOrigin($after['production_member_origins'][0], $policy);
            $activation = MemberActivationRecords::readActivation($after['production_member_activations'][0]);
            $this->assertSame($activate['authorization']['id'], $origin['authorization']['authorization_id']);
            $this->assertSame($activate['authorization']['hash'], CanonicalJson::hash($origin['authorization']));
            $this->assertSame($value['activationHash'], CanonicalJson::hash($activation));
            $this->assertSame($fixture['reservation']['redemption_id'], $value['originId']);
            $this->assertSame($value['originId'], $value['redemptionId']);
            $this->assertCount(1, $paths);
            $hash = hash_file('sha256', $paths[0]);
            $inode = fileinode($paths[0]);
            $this->assertSame($after['production_member_artifacts'][0]['sha256'], $hash);
            $this->assertSame($value, get_object_vars($this->readMemberActivation($fixture)));
            $this->assertSame($after, $this->memberOriginalEffects());
            $this->assertSame($hash, hash_file('sha256', $paths[0]));
            $this->assertSame($inode, fileinode($paths[0]));
        } else {
            $this->assertSame([], $paths);
        }
        $this->assertSame($audit, $this->ledgerSnapshot($fixture));
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
        $this->transcript['retained_rows_sha256'] = CanonicalJson::hash($after);
        $this->transcript['retained_row_counts'] = array_map(count(...), $after);
        $this->transcript['terminal'] = ['id' => $terminals[0]->id, 'kind' => $kind, 'balances' => $audit['balances']];
    }

    private function worker(array $fixture, string $role, bool $hold, array $walls): array
    {
        $db = DB::connection()->getConfig();
        $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('P', 32)),
            'DB_CONNECTION' => 'mysql', 'DB_URL' => '', 'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBER_ACTIVATION_RELEASE_RACE_ONLY' => '1', 'VA_MEMBER_ACTIVATION_RELEASE_RACE_DIRECTORY' => $this->directory];
        $input = ['role' => $role, 'hold' => $hold, 'user_id' => (int) $fixture['buyer']['user']->id,
            'definition_id' => $fixture['member_authored']->definitionId, 'redemption_id' => $fixture['reservation']['redemption_id'],
            'invoice_id' => $fixture['invoice']['id'], 'confirm_at' => $walls['confirm'], 'operation_at' => $walls[$role],
            'configuration' => ['production-customer-identity' => config('production-customer-identity'),
                'production-memberships' => config('production-memberships'), 'production-membership-billing' => config('production-membership-billing'),
                'member-grants' => config('member-grants'), 'filesystems.disks.local' => config('filesystems.disks.local'), 'media' => config('media')]];
        $process = new Process([PHP_BINARY, base_path('tests/Support/member-activation-release-race-worker.php')], base_path(), $environment,
            json_encode($input, JSON_THROW_ON_ERROR), 300);
        $process->start();
        $this->workers[$role] = ['role' => $role, 'process' => $process];
        $this->transcript['workers'][$role] = ['command' => $process->getCommandLine(), 'spawned_pid' => $process->getPid(),
            'confirm_at' => $input['confirm_at'], 'operation_at' => $input['operation_at'], 'hold' => $hold];
        $this->await(fn (): bool => is_file($this->directory.'/ready-'.$role), 100);
        $ready = json_decode(file_get_contents($this->directory.'/ready-'.$role), true, 8, JSON_THROW_ON_ERROR);
        $this->workers[$role] += $ready;
        $this->transcript['workers'][$role]['ready'] = $ready;
        $this->writeReceipt();
        $this->assertSame(0, $ready['transaction_level']);
        $this->assertFalse($ready['pdo_transaction']);

        return $this->workers[$role];
    }

    private function start(array $worker): void
    {
        $this->transcript['workers'][$worker['role']]['released_ns'] = hrtime(true);
        touch($this->directory.'/start-'.$worker['role']);
    }

    private function finish(array $worker): array
    {
        $worker['process']->wait();
        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getOutput().$worker['process']->getErrorOutput());
        $result = json_decode($worker['process']->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $this->transcript['workers'][$worker['role']]['result'] = $result;
        $this->writeReceipt();
        $this->assertSame($worker['role'], $result['role']);
        $this->assertSame($worker['connection_id'], $result['connection_id']);
        $this->assertSame($worker['pid'], $result['pid']);
        $this->assertSame(0, $result['transaction_level']);
        $this->assertFalse($result['pdo_transaction']);

        return $result;
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

    private function await(callable $condition, int $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($this->workers as $worker) {
                if (! $worker['process']->isRunning()) {
                    $this->fail('Terminal worker ended before native barrier: '.$worker['process']->getOutput().$worker['process']->getErrorOutput());
                }
                $worker['process']->checkTimeout();
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);
        $this->fail('Terminal worker did not reach its bounded native barrier.');
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
