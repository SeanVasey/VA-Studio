<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingAttemptRecord;
use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Billing\BillingEvidenceRecord;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingRetrievalAttempts;
use App\Domain\Memberships\Billing\BillingRetrievalSchema;
use App\Domain\Memberships\Billing\BillingRetrievalStart;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\BillingVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Mockery;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InterleavingBillingGateway;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

class BillingIssuedRetrievalTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_values_copies_and_another_issuer_cannot_adopt_a_start_or_interval(): void
    {
        $ledger = new BillingLedger;
        $start = $ledger->startRetrieval();
        foreach ([clone $start, new BillingRetrievalStart($start->position, $start->allocationToken, $start->retrievalToken, $start->databaseAt)] as $copy) {
            $this->refused(fn () => $ledger->endRetrieval($copy), 'foreign_retrieval');
        }
        $this->refused(fn () => (new BillingLedger)->endRetrieval($start), 'foreign_retrieval');
        $interval = $ledger->endRetrieval($start);
        $this->assertGreaterThan($start->position, $interval->endPosition);
        $this->assertNotSame($start->allocationToken, $interval->endAllocationToken);
        $this->refused(fn () => $ledger->endRetrieval($start), 'foreign_retrieval');
        $this->refused(fn () => $ledger->append(['id' => BillingValues::id()], BillingVerdict::unknown('provider_unavailable'), 'synthetic_rehearsal', clone $interval), 'foreign_retrieval');
        $this->assertSame(2, DB::table(BillingSchema::TABLES[4])->count());
        $this->assertSame(0, DB::table(BillingSchema::TABLES[2])->count());
    }

    public static function allocationLossStages(): array
    {
        return ['start before end' => ['end', false], 'start before append' => ['append', false],
            'end before append' => ['append', true], 'end before attempt' => ['attempt', true]];
    }

    #[DataProvider('allocationLossStages')]
    public function test_simulated_allocation_replacement_at_the_same_numeric_id_cannot_be_adopted(string $operation, bool $replaceEnd): void
    {
        $bindingRow = F::binding();
        $configuration = (new BillingPolicy)->current();
        $ledger = new BillingLedger;
        $binding = $ledger->binding($bindingRow['id'], $configuration);
        $invoice = $ledger->invoice($binding, F::INVOICE);
        $start = $ledger->startRetrieval();
        $interval = $operation === 'end' ? null : $ledger->endRetrieval($start);
        $position = $replaceEnd ? $interval->endPosition : $start->position;
        // Local retained-state simulation only: this is not a database failover or restore test.
        $this->replaceAllocationToken($position);
        (new BillingRetrievalSchema)->assertOwned(DB::connection()->getPdo());
        $this->refused(fn () => match ($operation) {
            'end' => $ledger->endRetrieval($start),
            'append' => $ledger->append($invoice, BillingVerdict::unknown('provider_unavailable'), 'synthetic_rehearsal', $interval),
            'attempt' => (new BillingRetrievalAttempts)->record($binding, F::INVOICE, 'provider_unavailable', $interval, null, $configuration),
        }, 'changed_retrieval');
        $this->assertSame($operation === 'end' ? 1 : 2, DB::table(BillingSchema::TABLES[4])->count());
        $this->assertSame(0, DB::table(BillingSchema::TABLES[2])->count());
        $this->assertSame(0, DB::table(BillingSchema::TABLES[5])->count());
    }

    public function test_replaced_endpoint_after_end_cannot_claim_the_first_invoice_identity(): void
    {
        $bindingRow = F::binding();
        $ledger = new BillingLedger;
        $binding = $ledger->binding($bindingRow['id'], (new BillingPolicy)->current());
        $interval = $ledger->endRetrieval($ledger->startRetrieval());
        $this->replaceAllocationToken($interval->endPosition);
        (new BillingRetrievalSchema)->assertOwned(DB::connection()->getPdo());
        $this->refused(fn () => $ledger->invoiceForRetrieval($binding, F::INVOICE, $interval), 'changed_retrieval');
        $this->assertSame(0, DB::table(BillingSchema::TABLES[1])->count());
        $this->assertSame(0, DB::table(BillingSchema::TABLES[2])->count());
        $this->assertSame(2, DB::table(BillingSchema::TABLES[4])->count());
    }

    public function test_database_end_follows_provider_reads_and_worker_time_cannot_change_paid_time_or_freshness(): void
    {
        $binding = F::binding();
        $pdo = DB::connection()->getPdo();
        $before = BillingDatabaseClock::sample($pdo, DB::getDriverName());
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($before->seconds() + 7200));
        $sawLastRead = false;
        $gateway = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function () use (&$sawLastRead): void {
            $this->assertSame(0, DB::transactionLevel());
            $this->assertFalse(DB::connection()->getPdo()->inTransaction());
            $this->assertSame(1, DB::table(BillingSchema::TABLES[4])->where('phase', 'start')->count());
            $this->assertSame(0, DB::table(BillingSchema::TABLES[4])->where('phase', 'end')->count());
            $sawLastRead = true;
        });
        $ledger = new BillingLedger;
        $row = (new BillingReconciliation($gateway, $ledger))->retrieve($binding['id'], F::INVOICE);
        $after = BillingDatabaseClock::sample($pdo, DB::getDriverName());
        $this->assertTrue($sawLastRead);
        $this->assertSame('settled', $row['outcome']);
        $payload = BillingEvidenceRecord::verify($row, BillingEvidenceRecord::OBSERVATION);
        $this->assertSame('2026-10-07 00:00:00', $payload['facts']['paid_at']);
        $this->assertGreaterThanOrEqual($before->stamp19(), $row['retrieved_at']);
        $this->assertLessThanOrEqual($after->stamp19(), $row['retrieved_at']);
        $this->assertLessThanOrEqual($after->seconds() + 600, strtotime($row['freshness_deadline'].' UTC'));
        $this->assertSame($row['id'], $ledger->currentSettled($row['invoice_id'])['id']);
        $ledger->assertRetainedObservationInterval($row);
    }

    public function test_a_newer_reversal_on_a_clock_behind_worker_is_admitted_after_an_ahead_worker(): void
    {
        $binding = F::binding();
        $now = BillingDatabaseClock::sample(DB::connection()->getPdo(), DB::getDriverName());
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($now->seconds() + 7200));
        $ledger = new BillingLedger;
        $first = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($now->seconds() - 7200));
        $second = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]])), $ledger))
            ->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['reversed', 2, $first['seal']], [$second['outcome'], $second['sequence'], $second['prior_seal']]);
        $this->assertGreaterThanOrEqual($first['created_at'], $second['created_at']);
        $this->assertNull($ledger->currentSettled($first['invoice_id']));
        $this->assertCount(2, $ledger->observations($first['invoice_id']));
    }

    public function test_native_duplicate_first_identity_uses_a_current_read_after_its_earlier_snapshot(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Native REPEATABLE READ duplicate identity visibility.');
        }
        $bindingRow = F::binding();
        $ledger = new BillingLedger;
        $binding = $ledger->binding($bindingRow['id'], (new BillingPolicy)->current());
        $interval = $ledger->endRetrieval($ledger->startRetrieval());
        $pdo = DB::connection()->getPdo();
        $this->assertSame('REPEATABLE-READ', $pdo->query('SELECT @@transaction_isolation')->fetchColumn());
        $settings = config('database.connections.mysql');
        $address = ($settings['unix_socket'] ?? '') !== '' ? 'unix_socket='.$settings['unix_socket']
            : 'host='.$settings['host'].';port='.$settings['port'];
        $other = new PDO('mysql:'.$address.';dbname='.$settings['database'].';charset=utf8mb4', $settings['username'], $settings['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $table = (new BillingSchema)->table(BillingSchema::TABLES[1]);
        $winnerId = BillingValues::id();
        $real = Crypt::getFacadeRoot();
        $fake = Mockery::mock($real)->makePartial();
        $fake->shouldReceive('encryptString')->once()->andReturnUsing(function (string $plain) use ($real, $binding, $pdo, $other, $table, $winnerId): string {
            $this->assertTrue($pdo->inTransaction());
            $cipher = $real->encryptString($plain);
            $expectation = $binding['expectation'];
            $winner = ['id' => $winnerId, 'subscription_binding_id' => $binding['row']['id'],
                'invoice_ref_hash' => BillingValues::hash('invoice', $expectation->accountRef, $expectation->mode, F::INVOICE),
                'source_invoice_hash' => BillingValues::hash('source-invoice', $expectation->accountRef, $expectation->mode, F::INVOICE),
                'provider_account_hash' => $binding['row']['provider_account_hash'], 'mode' => $expectation->mode,
                'payload_ciphertext' => $cipher, 'created_at' => BillingDatabaseClock::sample($other, 'mysql')->stamp19()];
            $winner['seal'] = BillingValues::seal($winner);
            $statement = $other->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($winner)).') VALUES ('.implode(', ', array_fill(0, count($winner), '?')).')');
            $statement->execute(array_values($winner));
            $this->assertSame(1, $statement->rowCount());
            // The first identity lookup already established the older RR snapshot.
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());
            $this->assertSame(1, (int) $other->query('SELECT COUNT(*) FROM '.$table)->fetchColumn());

            return $cipher;
        });
        Crypt::swap($fake);
        try {
            $retained = $ledger->invoiceForRetrieval($binding, F::INVOICE, $interval);
        } finally {
            Crypt::swap($real);
        }
        $this->assertSame($winnerId, $retained['id']);
        $this->assertSame(1, DB::table(BillingSchema::TABLES[1])->count());
        $this->assertSame(0, DB::table(BillingSchema::TABLES[2])->count());
    }

    public function test_failed_first_retrieval_retains_a_database_interval_without_claiming_invoice_ownership(): void
    {
        $binding = F::binding();
        $before = BillingDatabaseClock::sample(DB::connection()->getPdo(), DB::getDriverName());
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC($before->seconds() + 7200));
        $this->refused(fn () => (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')))->retrieve($binding['id'], F::INVOICE), 'provider_unavailable');
        $row = BillingValues::integers((array) DB::table(BillingSchema::TABLES[5])->sole(), ['retrieval_position', 'retrieval_end_position']);
        $this->assertSame(2, BillingAttemptRecord::verify($row)['schema_version']);
        $after = BillingDatabaseClock::sample(DB::connection()->getPdo(), DB::getDriverName());
        $this->assertGreaterThanOrEqual($before->stamp19(), $row['created_at']);
        $this->assertLessThanOrEqual($after->stamp19(), $row['created_at']);
        $this->assertSame(0, DB::table(BillingSchema::TABLES[1])->count());
        $this->assertSame(0, DB::table(BillingSchema::TABLES[2])->count());
        $this->assertSame(2, DB::table(BillingSchema::TABLES[4])->count());
    }

    private function replaceAllocationToken(int $id): void
    {
        $pdo = DB::connection()->getPdo();
        $name = BillingSchema::TABLES[4].'_update';
        if (DB::getDriverName() === 'mysql') {
            $definition = $pdo->query('SHOW CREATE TRIGGER `'.$name.'`')->fetch(PDO::FETCH_ASSOC)['SQL Original Statement'];
        } else {
            $query = $pdo->prepare("SELECT sql FROM main.sqlite_master WHERE type='trigger' AND name=?");
            $query->execute([$name]);
            $definition = $query->fetchColumn();
        }
        $this->assertIsString($definition);
        $pdo->exec('DROP TRIGGER `'.$name.'`');
        try {
            $update = $pdo->prepare('UPDATE '.(new BillingSchema)->table(BillingSchema::TABLES[4]).' SET allocation_token = ? WHERE id = ?');
            $update->execute([bin2hex(random_bytes(32)), $id]);
            $this->assertSame(1, $update->rowCount());
        } finally {
            $pdo->exec($definition);
        }
    }

    private function refused(\Closure $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Expected '.$reason.'.');
        } catch (BillingException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }
}
