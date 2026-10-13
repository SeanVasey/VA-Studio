<?php

namespace Runtime\BillingPreC3;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingSettlement;
use App\Domain\Memberships\Billing\BillingValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** Original-API regression probes. Synthetic SQLite allocation-loss simulation, never a restore/failover claim. */
final class BillingPreC3RegressionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', DB::getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('/workspace/VA-Studio-billing-c3-baseline', base_path());
        F::configure();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($this->databaseNow()));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_replaced_start_allocation_must_not_admit_the_retained_old_integer(): void
    {
        $ledger = new BillingLedger;
        [$invoice, $verdict, $retrievedAt, $startedAt] = $this->settledInputs($ledger);
        $retainedStart = $ledger->startRetrieval();
        $original = $this->position($retainedStart);
        $this->simulateAllocationLoss($retainedStart);
        CarbonImmutable::setTestNow($startedAt->addSecond());
        $replacementStart = $ledger->startRetrieval();
        $replacement = $this->position($replacementStart);
        $this->assertSame($retainedStart, $replacementStart, 'The same numeric ID was allocated again after simulated allocation loss.');
        $this->assertNotSame($original['created_at'], $replacement['created_at']);

        // Exact guards are restored before either API receives the retained original integer.
        $end = $ledger->endRetrieval($retainedStart);
        $row = $ledger->append($invoice, $verdict, $retrievedAt, 'synthetic_rehearsal', $retainedStart, $end, $startedAt);
        $this->assertSame('settled', $row['outcome']);
        $this->assertSame($retainedStart, $row['retrieval_position']);
        $count = DB::table(BillingSchema::TABLES[2])->count();
        $this->assertSame(1, $count);
        $this->record('cp2-start', ['simulation' => 'allocation loss', 'original' => $original, 'replacement' => $replacement,
            'observed' => 'settled append accepted retained old start integer', 'observation_count' => $count]);

        $this->assertSame(0, $count, 'Desired C3 behavior: refuse the lost start allocation before any observation is appended; old source admitted a settled observation.');
    }

    public function test_replaced_end_allocation_must_not_admit_the_retained_old_integer(): void
    {
        $ledger = new BillingLedger;
        [$invoice, $verdict, $retrievedAt, $startedAt] = $this->settledInputs($ledger);
        $start = $ledger->startRetrieval();
        $retainedEnd = $ledger->endRetrieval($start);
        $original = $this->position($retainedEnd);
        $this->simulateAllocationLoss($retainedEnd);
        CarbonImmutable::setTestNow($startedAt->addSecond());
        $replacementEnd = $ledger->endRetrieval($start);
        $replacement = $this->position($replacementEnd);
        $this->assertSame($retainedEnd, $replacementEnd, 'The same numeric ID was allocated again after simulated allocation loss.');
        $this->assertNotSame($original['created_at'], $replacement['created_at']);

        $row = $ledger->append($invoice, $verdict, $retrievedAt, 'synthetic_rehearsal', $start, $retainedEnd, $startedAt);
        $this->assertSame('settled', $row['outcome']);
        $this->assertSame($retainedEnd, $row['retrieval_end_position']);
        $count = DB::table(BillingSchema::TABLES[2])->count();
        $this->assertSame(1, $count);
        $this->record('cp2-end', ['simulation' => 'allocation loss', 'original' => $original, 'replacement' => $replacement,
            'observed' => 'settled append accepted retained old end integer', 'observation_count' => $count]);

        $this->assertSame(0, $count, 'Desired C3 behavior: refuse the lost end allocation before any observation is appended; old source admitted a settled observation.');
    }

    public function test_a_later_reversal_must_not_be_rejected_because_the_previous_worker_clock_was_ahead(): void
    {
        $databaseAt = $this->databaseNow();
        $binding = F::binding();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($databaseAt + 7200));
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame('settled', $settled['outcome']);
        $this->assertSame(BillingValues::utc($databaseAt + 7200), $settled['created_at']);

        // Actual ordered retrieval occurs later; only this worker's wall clock is corrected.
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($databaseAt));
        $outcome = null;
        try {
            $reversed = (new BillingReconciliation(new RehearsalBillingGateway(F::graph([
                'charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT],
            ]))))->retrieve($binding['id'], F::INVOICE);
            $outcome = $reversed['outcome'];
        } catch (BillingException $error) {
            $outcome = $error->reason;
        }
        $chain = (new BillingLedger)->observations($settled['invoice_id']);
        $this->assertSame('clock', $outcome, 'Baseline setup must reach the old clock refusal, not another error.');
        $this->assertSame(['settled'], array_column($chain, 'outcome'));
        $this->assertSame(4, DB::table(BillingSchema::TABLES[4])->count(), 'Both nonoverlapping start/end pairs were committed.');
        $this->record('cp3-reversal', ['database_at' => BillingValues::utc($databaseAt), 'ahead_seconds' => 7200,
            'first_created_at' => $settled['created_at'], 'later_retrieval_result' => $outcome, 'retained_outcomes' => array_column($chain, 'outcome')]);

        $this->assertSame('reversed', $outcome, 'Desired C3 behavior: retain the later reversal using database chronology; old source loses it with reason clock.');
    }

    public function test_worker_clock_ahead_must_not_extend_freshness_beyond_the_original_database_window(): void
    {
        $databaseAt = $this->databaseNow();
        $binding = F::binding();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($databaseAt + 7200));
        $ledger = new BillingLedger;
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $databaseAfter = $this->databaseNow();
        $this->assertSame('settled', $settled['outcome']);
        $this->assertSame(BillingValues::utc($databaseAt + 7200 + BillingLedger::FRESHNESS_SECONDS), $settled['freshness_deadline']);
        $simulatedLaterDatabaseAt = $databaseAt + 1200;
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($simulatedLaterDatabaseAt));
        // No sleep/database-clock modification: currentSettled's original public integer-now parameter represents later time.
        $current = $ledger->currentSettled($settled['invoice_id'], $simulatedLaterDatabaseAt);
        $this->assertSame($settled['id'], $current['id'] ?? null, 'Baseline demonstrates the settled tail is still returned after twice its intended 600-second window.');
        $this->record('cp3-freshness', ['database_sample_at' => BillingValues::utc($databaseAt), 'database_after_retrieval' => BillingValues::utc($databaseAfter), 'ahead_seconds' => 7200,
            'retrieved_at' => $settled['retrieved_at'], 'freshness_deadline' => $settled['freshness_deadline'],
            'simulated_caller_now' => BillingValues::utc($simulatedLaterDatabaseAt), 'elapsed_from_database_sample_seconds' => 1200,
            'old_api_returns_current' => $current !== null]);

        $this->assertLessThanOrEqual($databaseAfter + BillingLedger::FRESHNESS_SECONDS, strtotime($settled['freshness_deadline'].' UTC'),
            'Desired C3 behavior: immutable freshness deadline is no later than database read-end plus 600 seconds; old source derives it from the ahead worker clock.');
    }

    private function settledInputs(BillingLedger $ledger): array
    {
        $binding = F::binding();
        $read = $ledger->binding($binding['id'], (new BillingPolicy)->current());
        $at = CarbonImmutable::now('UTC');
        $verdict = BillingSettlement::evaluate(F::snapshots(F::graph(), $at->timestamp), $read['expectation']);
        $this->assertSame('settled', $verdict->outcome);

        return [$ledger->invoice($read, F::INVOICE), $verdict, $at->timestamp, $at];
    }

    private function position(int $id): array
    {
        $statement = DB::connection()->getPdo()->prepare('SELECT id, kind, created_at FROM '.BillingSchema::TABLES[4].' WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $this->assertIsArray($row);

        return $row;
    }

    private function simulateAllocationLoss(int $id): void
    {
        $pdo = DB::connection()->getPdo();
        $table = BillingSchema::TABLES[4];
        $guard = $table.'_delete';
        $statement = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?");
        $statement->execute([$guard]);
        $originalSql = $statement->fetchColumn();
        $this->assertIsString($originalSql);
        $this->assertSame($id, (int) $pdo->query('SELECT MAX(id) FROM '.$table)->fetchColumn(), 'Simulation replaces only the newest unreferenced allocation.');
        $pdo->exec('DROP TRIGGER "'.$guard.'"');
        try {
            $delete = $pdo->prepare('DELETE FROM '.$table.' WHERE id = ?');
            $delete->execute([$id]);
            $this->assertSame(1, $delete->rowCount());
            $counter = $pdo->prepare('UPDATE sqlite_sequence SET seq = ? WHERE name = ?');
            $counter->execute([$id - 1, $table]);
            $this->assertSame(1, $counter->rowCount());
        } finally {
            $pdo->exec($originalSql);
        }
        $statement->execute([$guard]);
        $this->assertSame($originalSql, $statement->fetchColumn(), 'Exact owned trigger SQL restored before the API under test.');
        (new BillingSchema)->assertOwned($pdo);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function databaseNow(): int
    {
        return (int) DB::connection()->getPdo()->query("SELECT strftime('%s', 'now')")->fetchColumn();
    }

    private function record(string $name, array $facts): void
    {
        file_put_contents(__DIR__.'/'.$name.'.json', json_encode($facts, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
    }
}
