<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAttemptRecord;
use App\Domain\Memberships\Billing\BillingDatabaseInstant;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingRetrievalInterval;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingValues;
use App\Support\CanonicalJson;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Symfony\Component\Process\Process;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** Actual263 guards and independently issued endpoints; diagnostic synthetic records, never paid authority. */
class BillingNativeDatabaseIntervalOwnershipRaceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private array $transcript = [];

    private ?string $receipt = null;

    public function test_two_older_snapshots_cannot_claim_the_same_valid_database_interval_across_ledgers(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Upgraded263 endpoint arbitration requires independent native MySQL processes and observed row waits.');
        }
        F::configure();
        $pdo = DB::connection()->getPdo();
        (new BillingRetrievalSchema)->assertOwned($pdo);
        $binding = F::binding();
        $ledger = new BillingLedger;
        $interval = $ledger->endRetrieval($ledger->startRetrieval());
        $this->assertInstanceOf(BillingRetrievalInterval::class, $interval);
        $positions = (new BillingSchema)->table(BillingSchema::TABLES[4]);
        $query = $pdo->prepare('SELECT * FROM '.$positions.' WHERE id IN (?, ?) ORDER BY id');
        $query->execute([$interval->start->position, $interval->endPosition]);
        [$start, $end] = $query->fetchAll(PDO::FETCH_ASSOC);
        $rows = $this->candidates($binding, $interval, $start, $end);
        foreach ($rows as $table => $row) {
            BillingRetrievalInterval::verifyRows($row, $start, $end);
            // Each exact contender must independently satisfy every upgraded SQL guard before the race.
            DB::beginTransaction();
            try {
                $this->insert($table, $row);
                $this->assertSame(1, DB::table($table)->where('id', $row['id'])->count());
            } finally {
                DB::rollBack();
            }
            $this->assertSame(0, DB::table($table)->where('id', $row['id'])->count());
            $this->assertFalse($pdo->inTransaction());
        }
        $this->assertSame(0, DB::transactionLevel());
        $results = $this->race($rows, (int) $start['id']);
        $saved = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'saved'));
        $denied = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'denied'));
        $this->assertCount(1, $saved, json_encode($results));
        $this->assertCount(1, $denied, json_encode($results));
        $loser = $denied[0];
        $this->assertTrue($loser['sqlstate'] === '45000'
            || ($loser['sqlstate'] === '40001' && $loser['driver_code'] === 1213), json_encode($loser));
        $emptySnapshot = [BillingSchema::TABLES[2] => 0, BillingSchema::TABLES[5] => 0];
        foreach ($results as $result) {
            $this->assertSame($emptySnapshot, $result['snapshot_before']);
            $this->assertSame(0, $result['transaction_level']);
            $this->assertFalse($result['in_transaction']);
        }
        if ($loser['sqlstate'] === '45000') {
            // The failed statement leaves the earlier snapshot active: it still hides the winner's commit.
            $this->assertSame($emptySnapshot, $loser['snapshot_after']);
        } else {
            // InnoDB may already have rolled back a deadlock victim; no post-deadlock snapshot claim.
            $this->assertNull($loser['snapshot_after']);
        }
        $this->assertSame(1, DB::table(BillingSchema::TABLES[2])->where('id', $rows[BillingSchema::TABLES[2]]['id'])->count()
            + DB::table(BillingSchema::TABLES[5])->where('id', $rows[BillingSchema::TABLES[5]]['id'])->count());
        foreach ($rows as $table => $expected) {
            $retained = DB::table($table)->where('id', $expected['id'])->first();
            if ($retained !== null) {
                $actual = BillingValues::integers((array) $retained, ['sequence', 'retrieval_position', 'retrieval_end_position']);
                BillingRetrievalInterval::verifyRows($actual, $start, $end);
                $payload = $table === BillingSchema::TABLES[2]
                    ? BillingEvidenceRecord::verify($actual, BillingEvidenceRecord::OBSERVATION) : BillingAttemptRecord::verify($actual);
                $this->assertSame(BillingEvidenceRecord::CLOCK_BASIS, $payload['clock_basis']);
                $this->assertSame(CanonicalJson::encode($expected), CanonicalJson::encode($actual));
            }
        }
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($pdo->inTransaction());
    }

    private function candidates(array $binding, BillingRetrievalInterval $interval, array $start, array $end): array
    {
        $id = (string) Str::uuid();
        $reference = 'in_SYNTHETICDATABASEINTERVAL';
        $invoice = ['id' => $id, 'subscription_binding_id' => $binding['id'],
            'invoice_ref_hash' => BillingValues::hash('invoice', F::ACCOUNT, 'test', $reference),
            'source_invoice_hash' => BillingValues::hash('source-invoice', F::ACCOUNT, 'test', $reference),
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test',
            'payload_ciphertext' => 'synthetic structural identity, not invoice authority', 'seal' => hash('sha256', 'synthetic'.$id),
            'created_at' => $end['created_at']];
        $this->insert(BillingSchema::TABLES[1], $invoice);
        $facts = ['outcome' => 'unknown', 'reason' => 'provider_unavailable', 'facts' => []];
        $observation = ['id' => (string) Str::uuid(), 'invoice_id' => $id, 'sequence' => 1, 'outcome' => 'unknown',
            'facts_hash' => CanonicalJson::hash($facts), 'line_period_start' => null, 'line_period_end' => null,
            'amount_minor' => null, 'currency' => null, 'retrieved_at' => $end['created_at'],
            'retrieval_started_at' => $start['database_at'], ...$interval->columns(),
            'freshness_deadline' => BillingValues::utc(BillingDatabaseInstant::fromNativeValue($end['database_at'], 0)->seconds() + BillingLedger::FRESHNESS_SECONDS),
            'api_version' => BillingProviderPin::API_VERSION, 'sdk_reference' => BillingProviderPin::SDK_REFERENCE,
            'prior_seal' => str_repeat('0', 64), 'created_at' => $end['created_at']];
        $observation['payload_ciphertext'] = BillingEvidenceRecord::encrypt($observation,
            ['purpose' => BillingEvidenceRecord::OBSERVATION, ...$facts, 'provenance' => $binding['provenance']]);
        $observation['seal'] = BillingValues::seal($observation);
        $attempt = ['id' => (string) Str::uuid(), 'subscription_binding_id' => $binding['id'], 'invoice_ref_hash' => $invoice['invoice_ref_hash'],
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test', 'provenance' => $binding['provenance'],
            'hint_event_id' => null, ...$interval->columns(), 'outcome' => 'unknown', 'reason' => 'provider_unavailable',
            'created_at' => $end['created_at']];
        $attempt['payload_ciphertext'] = BillingAttemptRecord::encrypt($attempt, ['invoice_ref' => $reference, 'subscription_binding_id' => $binding['id']]);
        $attempt['seal'] = BillingValues::seal($attempt);
        $this->assertSame(3, BillingEvidenceRecord::verify($observation, BillingEvidenceRecord::OBSERVATION)['schema_version']);
        $this->assertSame(2, BillingAttemptRecord::verify($attempt)['schema_version']);

        return [BillingSchema::TABLES[2] => $observation, BillingSchema::TABLES[5] => $attempt];
    }

    private function race(array $rows, int $startId): array
    {
        $id = (string) Str::uuid();
        $directory = storage_path('framework/testing/membership-database-interval-'.$id);
        $evidence = getenv('VA_MEMBERSHIP_DATABASE_INTERVAL_RACE_EVIDENCE_DIRECTORY') ?: storage_path('framework/testing/membership-database-interval-evidence');
        foreach ([$directory, $evidence] as $path) {
            (new Filesystem)->ensureDirectoryExists($path, 0700, true);
        }
        $this->receipt = $evidence.'/'.$this->name().'-'.$id.'.json';
        $pdo = DB::connection()->getPdo();
        $parent = (int) $pdo->query('SELECT CONNECTION_ID()')->fetchColumn();
        $this->transcript = ['case' => $this->name(), 'parent_pid' => getmypid(), 'parent_connection_id' => $parent,
            'mysql_version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'database' => DB::getDatabaseName(),
            'scope' => 'Upgraded263 raw SQL arbitration over one real issued interval and explicit synthetic diagnostic records; no paid authority.',
            'positive_controls' => 'Both exact candidate inserts passed independently and were rolled back before workers started.',
            'start_id' => $startId, 'waits' => [], 'workers' => []];
        $processes = [];
        try {
            DB::beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM '.(new BillingSchema)->table(BillingSchema::TABLES[4]).' WHERE id = ? FOR UPDATE');
            $lock->execute([$startId]);
            $this->assertSame($startId, (int) $lock->fetchColumn());
            foreach (array_keys($rows) as $index => $table) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/membership-interval-race-worker.php')], base_path(),
                    $this->environment($directory, $index), json_encode(['table' => $table, 'row' => $rows[$table]], JSON_THROW_ON_ERROR), 60);
                $process->start();
                $processes[$index] = $process;
            }
            $this->await(fn (): bool => is_file($directory.'/ready-0') && is_file($directory.'/ready-1'), $processes, 25);
            $connections = [(int) file_get_contents($directory.'/ready-0'), (int) file_get_contents($directory.'/ready-1')];
            $this->assertCount(3, array_unique([...$connections, $parent]));
            $this->transcript['worker_connections'] = $connections;
            touch($directory.'/start');
            $this->await(function () use ($connections, $parent, $startId): bool {
                foreach ($connections as $index => $connection) {
                    if ($this->waiting($connection, $parent, $startId)) {
                        $this->transcript['waits'][$index] = ['requester' => $connection, 'blocker' => $parent, 'start_id' => $startId];
                    }
                }

                return count($this->transcript['waits']) === 2;
            }, $processes, 10);
            $this->writeReceipt();
            DB::rollBack();
            $results = [];
            foreach ($processes as $index => $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
                $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
                $this->assertSame($connections[$index], $result['connection_id']);
                $results[] = $result;
                $this->transcript['workers'][$index]['result'] = $result;
                $this->writeReceipt();
            }
            $this->assertCount(3, array_unique([...array_column($results, 'pid'), getmypid()]));

            return $results;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $index => $process) {
                $stopped = $process->isRunning();
                if ($stopped) {
                    $process->stop(1);
                }
                $this->transcript['workers'][$index] = [...($this->transcript['workers'][$index] ?? []),
                    'stopped_by_cleanup' => $stopped, 'exit' => $process->getExitCode(),
                    'stdout' => $process->getOutput(), 'stderr' => $process->getErrorOutput()];
            }
            $this->writeReceipt();
            (new Filesystem)->deleteDirectory($directory);
        }
    }

    private function waiting(int $requester, int $blocker, int $startId): bool
    {
        $query = DB::connection()->getPdo()->prepare(<<<'SQL'
SELECT requested.LOCK_DATA AS row_key FROM performance_schema.data_lock_waits waits
JOIN performance_schema.threads requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
JOIN performance_schema.threads blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
JOIN performance_schema.data_locks requested ON requested.ENGINE_LOCK_ID = waits.REQUESTING_ENGINE_LOCK_ID AND requested.ENGINE = waits.ENGINE
WHERE waits.ENGINE = 'INNODB' AND requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?
AND requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = ? AND requested.INDEX_NAME = 'PRIMARY'
AND requested.LOCK_TYPE = 'RECORD' AND requested.LOCK_STATUS = 'WAITING' LIMIT 1
SQL);
        $query->execute([$requester, $blocker, DB::getDatabaseName(), DB::connection()->getTablePrefix().BillingSchema::TABLES[4]]);
        $row = $query->fetch(PDO::FETCH_ASSOC);

        return $row !== false && trim($row['row_key'], "'\"") === (string) $startId;
    }

    private function await(callable $condition, array $processes, int $seconds): void
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;
        do {
            clearstatcache();
            if ($condition()) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Interval worker exited before the barrier: '.$process->getOutput().$process->getErrorOutput());
                }
                $process->checkTimeout();
            }
            usleep(5000);
        } while (hrtime(true) < $deadline);
        $this->fail('Upgraded interval workers did not reach the bounded observed barrier.');
    }

    private function insert(string $table, array $row): void
    {
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table($table)
            .' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function environment(string $directory, int $index): array
    {
        $db = DB::connection()->getConfig();

        return ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'], 'DB_DATABASE' => (string) $db['database'],
            'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'], 'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''),
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            'VA_MEMBERSHIP_INTERVAL_RACE_ONLY' => '1', 'VA_MEMBERSHIP_INTERVAL_RACE_DIRECTORY' => $directory,
            'VA_MEMBERSHIP_INTERVAL_RACE_WORKER' => (string) $index];
    }

    private function writeReceipt(): void
    {
        if ($this->receipt !== null) {
            file_put_contents($this->receipt, json_encode($this->transcript, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL);
        }
    }
}
