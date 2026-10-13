<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingDatabaseClock;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingRetrievalAttempts;
use App\Domain\Memberships\Billing\BillingValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InterleavingBillingGateway;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** Synthetic retrieval diagnostics never claim ownership, settle an invoice or award credits. */
class BillingRetrievalAttemptsTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const TABLE = 'production_membership_billing_attempts';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3600));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public static function stages(): array
    {
        return [['account'], ['invoice'], ['payments'], ['intent'], ['charge'], ['transaction'], ['subscription']];
    }

    #[DataProvider('stages')]
    public function test_first_timeout_is_retained_without_claiming_an_invoice_and_retry_can_succeed(string $stage): void
    {
        $binding = F::binding();
        try {
            (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), $stage)))->retrieve($binding['id'], F::INVOICE);
            $this->fail('A first timeout must still throw for queue retry.');
        } catch (BillingException $error) {
            $this->assertSame('provider_unavailable', $error->reason);
        }

        $this->assertTrue(Schema::hasTable(self::TABLE), 'First unsuccessful retrievals need durable, non-identity attempt records.');
        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $this->assertSame(1, DB::table(self::TABLE)->count());
        $attempt = BillingValues::integers((array) DB::table(self::TABLE)->sole(), ['retrieval_position', 'retrieval_end_position']);
        $this->assertSame([$binding['id'], 'unknown', 'provider_unavailable', null],
            [$attempt['subscription_binding_id'], $attempt['outcome'], $attempt['reason'], $attempt['hint_event_id']]);
        $this->assertSame(BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE), $attempt['invoice_ref_hash']);
        $this->assertSame(BillingValues::seal($attempt), $attempt['seal']);
        $this->assertGreaterThan($attempt['retrieval_position'], $attempt['retrieval_end_position']);
        $this->assertStringNotContainsString(F::INVOICE, json_encode($attempt, JSON_THROW_ON_ERROR));
        $this->assertSame(F::INVOICE, BillingValues::decrypt($attempt['payload_ciphertext'])['invoice_ref']);

        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['settled', 1], [$settled['outcome'], $settled['sequence']]);
        $this->assertSame($attempt, BillingValues::integers((array) DB::table(self::TABLE)->sole(), ['retrieval_position', 'retrieval_end_position']));
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_repeated_wrong_binding_attempts_do_not_pin_the_invoice_or_disappear_when_the_correct_binding_succeeds(): void
    {
        $wrong = F::binding(['subscription_ref' => 'sub_WRONGSYNTHETIC']);
        $right = F::binding();
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->refusal(fn () => (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($wrong['id'], F::INVOICE),
                'binding_refused_subscription');
        }
        $before = DB::table(self::TABLE)->orderBy('retrieval_position')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertCount(3, $before);
        $this->assertCount(3, array_unique(array_column($before, 'id')));
        $this->assertSame([$wrong['id']], array_values(array_unique(array_column($before, 'subscription_binding_id'))));
        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($right['id'], F::INVOICE);
        $this->assertSame(['settled', 1], [$settled['outcome'], $settled['sequence']]);
        $this->assertSame($right['id'], DB::table('production_membership_billing_invoices')->value('subscription_binding_id'));
        $this->assertSame($before, DB::table(self::TABLE)->orderBy('retrieval_position')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_attempt_rows_are_immutable_and_invalid_outcomes_scopes_and_reused_intervals_are_refused(): void
    {
        $binding = F::binding();
        $this->refusal(fn () => (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')))->retrieve($binding['id'], F::INVOICE), 'provider_unavailable');
        $row = (array) DB::table(self::TABLE)->sole();
        $pdo = DB::connection()->getPdo();
        foreach (['UPDATE '.self::TABLE.' SET reason = reason', 'DELETE FROM '.self::TABLE] as $sql) {
            $this->pdoRefuses(fn () => $pdo->exec($sql));
        }
        $ledger = new BillingLedger;
        $start = $ledger->startRetrieval();
        $end = $ledger->endRetrieval($start);
        $candidate = [...$row, ...$end->columns(), 'id' => BillingValues::id(),
            'created_at' => BillingDatabaseClock::sample($pdo, DB::getDriverName())->stamp19()];
        foreach ([['outcome' => 'settled'], ['reason' => 'unbounded_provider_body'], ['provider_account_hash' => str_repeat('f', 64)],
            ['mode' => 'live'], ['hint_event_id' => BillingValues::id()], ['retrieval_end_position' => $start->position],
            ['retrieval_position' => $row['retrieval_position']], ['retrieval_position' => $row['retrieval_end_position']],
            ['retrieval_end_position' => $row['retrieval_position']], ['retrieval_end_position' => $row['retrieval_end_position']]] as $change) {
            $this->pdoRefuses(fn () => $this->insert([...$candidate, ...$change]));
        }
        $this->assertSame([$row], DB::table(self::TABLE)->get()->map(fn ($value) => (array) $value)->all());
        $this->assertSame(0, DB::table('production_membership_billing_invoices')->count());
    }

    public function test_configuration_withdrawn_during_provider_io_or_disabled_before_it_records_no_attempt(): void
    {
        $binding = F::binding();
        $gateway = new InterleavingBillingGateway(new RehearsalBillingGateway(F::graph()), function (): never {
            config(['production-membership-billing.enabled' => false]);
            throw new BillingException('provider_unavailable');
        });
        $this->refusal(fn () => (new BillingReconciliation($gateway))->retrieve($binding['id'], F::INVOICE), 'changed_policy');
        $off = new RehearsalBillingGateway(F::graph(), 'invoice');
        $this->refusal(fn () => (new BillingReconciliation($off))->retrieve($binding['id'], F::INVOICE), 'disabled');
        $this->assertSame([], $off->calls);
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSame(0, DB::table('production_membership_billing_invoices')->count());
        $this->assertSame(0, DB::table('production_membership_billing_observations')->count());
    }

    public static function wrongHints(): array
    {
        return [['invoice'], ['account'], ['late']];
    }

    #[DataProvider('wrongHints')]
    public function test_existing_wrong_scope_or_late_hint_is_refused_by_writer_and_database(string $kind): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $configuration = (new BillingPolicy)->current();
        $verified = $ledger->binding($binding['id'], $configuration);
        $start = $kind === 'late' ? $ledger->startRetrieval() : null;
        $hintPosition = DB::transaction(fn () => $ledger->position('hint'));
        $start ??= $ledger->startRetrieval();
        $end = $ledger->endRetrieval($start);
        $invoiceHash = BillingValues::hash('invoice', F::ACCOUNT, 'test', F::INVOICE);
        $hint = ['id' => BillingValues::id(), 'provider_event_ref_hash' => hash('sha256', 'synthetic attempt hint '.$kind),
            'type' => 'invoice.paid', 'mode' => 'test',
            'provider_account_hash' => $kind === 'account' ? BillingValues::hash('provider-account', 'acct_OTHERSYNTHETIC', 'test', 'acct_OTHERSYNTHETIC') : $binding['provider_account_hash'],
            'invoice_ref_hash' => $kind === 'invoice' ? BillingValues::hash('invoice', F::ACCOUNT, 'test', 'in_OTHERSYNTHETIC') : $invoiceHash,
            'received_at' => '2026-10-07 00:00:00', 'hint_position' => $hintPosition,
            'payload_hash' => hash('sha256', 'synthetic attempt hint body'), 'disposition' => 'retrieval_hint',
            'payload_ciphertext' => BillingValues::encrypt(['purpose' => 'synthetic_attempt_hint_fixture']), 'created_at' => '2026-10-07 00:00:00'];
        $hint['seal'] = BillingValues::seal($hint);
        $pdo = DB::connection()->getPdo();
        $pdo->prepare('INSERT INTO production_membership_billing_events ('.implode(', ', array_keys($hint)).') VALUES ('.implode(', ', array_fill(0, count($hint), '?')).')')->execute(array_values($hint));
        $this->refusal(fn () => (new BillingRetrievalAttempts)->record($verified, F::INVOICE, 'provider_unavailable', $end, $hint['id'], $configuration), 'attempt_hint');

        $row = ['id' => BillingValues::id(), 'subscription_binding_id' => $binding['id'], 'invoice_ref_hash' => $invoiceHash,
            'provider_account_hash' => $binding['provider_account_hash'], 'mode' => 'test', 'provenance' => $binding['provenance'],
            'hint_event_id' => $hint['id'], ...$end->columns(),
            'outcome' => 'unknown', 'reason' => 'provider_unavailable',
            'payload_ciphertext' => BillingValues::encrypt(['purpose' => 'synthetic_attempt_fixture']),
            'created_at' => BillingDatabaseClock::sample($pdo, DB::getDriverName())->stamp19()];
        $row['seal'] = BillingValues::seal($row);
        $this->pdoRefuses(fn () => $this->insert($row));
        $this->assertSame(0, DB::table(self::TABLE)->count());
        $this->assertSame(1, DB::table('production_membership_billing_events')->count());
        $this->assertSame(0, DB::table('production_membership_billing_invoices')->count());
    }

    private function refusal(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('The retrieval must retain its original refusal.');
        } catch (BillingException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function pdoRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database must refuse invalid or mutable attempt evidence.');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }

    private function insert(array $row): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->prepare('INSERT INTO '.self::TABLE.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')')->execute(array_values($row));
    }
}
