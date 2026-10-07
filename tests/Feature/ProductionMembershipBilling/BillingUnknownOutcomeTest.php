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

/** A timeout is retained as `unknown` under an invoice identity the binding already owns; a first one leaves no row. Nothing settles, awards or reverses. */
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
    public function test_first_timeout_leaves_no_ledger_row_and_a_later_one_is_appended_under_the_owned_identity(string $stage): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        try {
            (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), $stage), $ledger))->retrieve($binding['id'], F::INVOICE);
            $this->fail('A first retrieval that validated no provider graph claims no identity.');
        } catch (BillingException $error) {
            $this->assertSame('provider_unavailable', $error->reason);
        }
        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3700));
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['settled', 1], [$settled['outcome'], $settled['sequence']]);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3800));
        $unknown = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), $stage), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['unknown', 2, $settled['seal'], null, null], [$unknown['outcome'], $unknown['sequence'], $unknown['prior_seal'], $unknown['amount_minor'], $unknown['line_period_start']]);
        $this->assertSame('provider_unavailable', BillingValues::decrypt($unknown['payload_ciphertext'])['reason']);
        $this->assertNull($ledger->currentSettled($settled['invoice_id'], F::PERIOD_START + 3801));
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3900));
        $retry = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame([$settled['invoice_id'], 3, $unknown['seal'], 'settled'], [$retry['invoice_id'], $retry['sequence'], $retry['prior_seal'], $retry['outcome']]);
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
        $this->assertCount(3, $ledger->observations($settled['invoice_id']));
        $this->assertSame(0, DB::table('production_membership_paid_periods')->count());
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    /** Codex P2 (unknown-outcomes, Finding A): a mistaken binding that only saw a timeout must not pin the invoice. */
    public function test_a_timeout_under_the_wrong_binding_pins_nothing_and_the_right_binding_still_succeeds(): void
    {
        $wrong = F::binding(['subscription_ref' => 'sub_WRONGSYNTHETIC']);
        $right = F::binding();
        try {
            (new BillingReconciliation(new RehearsalBillingGateway(F::graph(), 'invoice')))->retrieve($wrong['id'], F::INVOICE);
            $this->fail('An unvalidated first retrieval records nothing.');
        } catch (BillingException $error) {
            $this->assertSame('provider_unavailable', $error->reason);
        }
        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($right['id'], F::INVOICE);
        $this->assertSame(['settled', 1], [$settled['outcome'], $settled['sequence']]);
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
    }

    public function test_ambiguous_provider_response_is_unknown_not_refused_and_is_recorded_only_under_an_owned_identity(): void
    {
        $binding = F::binding();
        $ambiguous = F::graph();
        unset($ambiguous['charges'][F::CHARGE]);
        try {
            (new BillingReconciliation(new RehearsalBillingGateway($ambiguous)))->retrieve($binding['id'], F::INVOICE);
            $this->fail('A first ambiguous retrieval records nothing.');
        } catch (BillingException $error) {
            $this->assertSame('provider_inconsistent', $error->reason);
        }
        $this->assertSame(0, DB::table('production_membership_billing_invoices')->count());
        $first = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3700));
        $observation = (new BillingReconciliation(new RehearsalBillingGateway($ambiguous)))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['unknown', 2, $first['seal']], [$observation['outcome'], $observation['sequence'], $observation['prior_seal']]);
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
