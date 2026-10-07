<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/** A timeout is retained as `unknown` under the original invoice identity; nothing settles, awards or reverses. */
class BillingUnknownOutcomeTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3600));
        F::configure();
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
    public function test_timeout_at_any_stage_appends_unknown_and_a_retry_continues_the_same_identity(string $stage): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $first = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), $stage), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['unknown', 1, null, null], [$first['outcome'], $first['sequence'], $first['amount_minor'], $first['line_period_start']]);
        $this->assertSame('provider_unavailable', BillingValues::decrypt($first['payload_ciphertext'])['reason']);
        $this->assertNull($ledger->currentSettled($first['invoice_id'], F::PERIOD_START + 3600));
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3700));
        $retry = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame([$first['invoice_id'], 2, $first['seal'], 'settled'], [$retry['invoice_id'], $retry['sequence'], $retry['prior_seal'], $retry['outcome']]);
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
        $this->assertCount(2, $ledger->observations($first['invoice_id']));
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_ambiguous_provider_response_is_unknown_not_refused(): void
    {
        $binding = F::binding();
        $graph = F::graph();
        unset($graph['charges'][F::CHARGE]);
        $observation = (new BillingReconciliation(new RehearsalBillingGateway($graph)))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame('unknown', $observation['outcome']);
        $this->assertSame('provider_inconsistent', BillingValues::decrypt($observation['payload_ciphertext'])['reason']);
    }

    public function test_configuration_refusal_records_no_observation(): void
    {
        $binding = F::binding();
        config(['production-membership-billing.enabled' => false]);
        try {
            (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
            $this->fail('Disabled billing must refuse.');
        } catch (BillingException $error) {
            $this->assertSame('disabled', $error->reason);
        }
        $this->assertSame(0, DB::table('production_membership_billing_observations')->count());
        $this->assertSame(0, DB::table('production_membership_billing_invoices')->count());
    }

    public function test_retrieval_inside_an_application_transaction_is_refused_before_provider_io(): void
    {
        $binding = F::binding();
        $gateway = new RehearsalBillingGateway(F::graph());
        DB::beginTransaction();
        try {
            (new BillingReconciliation($gateway))->retrieve($binding['id'], F::INVOICE);
            $this->fail('Provider I/O must not run inside a held transaction.');
        } catch (BillingException $error) {
            $this->assertSame('transaction_open', $error->reason);
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $gateway->calls);
    }
}
