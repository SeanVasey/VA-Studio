<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingLedger;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Domain\Memberships\Billing\BillingVerdict;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\RehearsalBillingGateway;
use Tests\TestCase;

/**
 * The read side a future paid-invoice authority consumes: the latest observation, only if settled and
 * inside its original freshness deadline. Never renewed, never reversed, and not itself a proof token.
 */
class BillingObservationLedgerTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private const AT = F::PERIOD_START + 3600;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::AT));
        F::configure();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_settled_observation_is_current_only_inside_its_original_deadline(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['settled', F::AMOUNT, F::CURRENCY, '2026-10-07 00:00:00', '2026-11-07 00:00:00'],
            [$settled['outcome'], $settled['amount_minor'], $settled['currency'], $settled['line_period_start'], $settled['line_period_end']]);
        $this->assertSame([F::PERIOD_START + 3600 + BillingLedger::FRESHNESS_SECONDS], [strtotime($settled['freshness_deadline'].' UTC')]);
        $this->assertSame($settled['id'], $ledger->currentSettled($settled['invoice_id'], self::AT + BillingLedger::FRESHNESS_SECONDS - 1)['id']);
        $this->assertNull($ledger->currentSettled($settled['invoice_id'], self::AT + BillingLedger::FRESHNESS_SECONDS));
    }

    public function test_newer_non_settled_observation_hides_an_older_settled_one_and_nothing_is_renewed(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::AT + 10));
        $refunded = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['charge' => ['refunded' => true, 'amount_refunded' => F::AMOUNT]])), $ledger))
            ->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['reversed', 2, $settled['seal']], [$refunded['outcome'], $refunded['sequence'], $refunded['prior_seal']]);
        $this->assertNull($ledger->currentSettled($settled['invoice_id'], self::AT + 20));
        // The settled row is retained unchanged; a reversal observation never deletes or rewrites it.
        $this->assertSame($settled, $ledger->observations($settled['invoice_id'])[0]);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::AT + 30));
        $again = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(BillingLedger::FRESHNESS_SECONDS + self::AT + 30, strtotime($again['freshness_deadline'].' UTC'));
        $this->assertSame($settled['freshness_deadline'], $ledger->observations($settled['invoice_id'])[0]['freshness_deadline']);
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_the_same_invoice_under_another_binding_is_a_conflict_not_a_second_identity(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $first = $ledger->invoice($ledger->binding($binding['id'], $this->configuration()), F::INVOICE);
        $this->assertSame($first, $ledger->invoice($ledger->binding($binding['id'], $this->configuration()), F::INVOICE));
        $other = F::binding(['subscription_ref' => 'sub_OTHERSYNTHETIC']);
        try {
            $ledger->invoice($ledger->binding($other['id'], $this->configuration()), F::INVOICE);
            $this->fail('One provider invoice cannot belong to two bindings.');
        } catch (BillingException $error) {
            $this->assertSame('conflicting_invoice', $error->reason);
        }
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
    }

    /**
     * Review R-3 (Codex P2): the immutable invoice-identity row is claimed only after the retrieved account, customer and
     * parent subscription match the binding, so a mistaken first dispatch cannot pin a real invoice to the wrong binding.
     *
     * @return array<string, array{0: array, 1: array, 2: string}> binding payload change, graph overrides, expected reason
     */
    public static function wrongBindingFirst(): array
    {
        return [
            'subscription' => [['subscription_ref' => 'sub_WRONGSYNTHETIC'], [], 'subscription'],
            'customer' => [[], ['invoice' => ['customer' => 'cus_FOREIGNSYNTHETIC']], 'customer'],
            'account' => [[], ['account' => 'acct_FOREIGNSYNTHETIC'], 'account'],
        ];
    }

    #[DataProvider('wrongBindingFirst')]
    public function test_wrong_binding_retrieved_first_claims_no_invoice_identity_and_the_right_binding_still_succeeds(array $bindingChange, array $graphChange, string $reason): void
    {
        $wrong = F::binding($bindingChange);
        $right = $bindingChange === [] ? $wrong : F::binding();
        try {
            (new BillingReconciliation(new RehearsalBillingGateway(F::graph($graphChange))))->retrieve($wrong['id'], F::INVOICE);
            $this->fail('A retrieval that contradicts its binding is refused, not recorded under that binding.');
        } catch (BillingException $error) {
            $this->assertSame('binding_refused_'.$reason, $error->reason);
        }
        $this->assertSame([0, 0], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($right['id'], F::INVOICE);
        $this->assertSame(['settled', 1], [$settled['outcome'], $settled['sequence']]);
        $this->assertSame([1, 1], [DB::table('production_membership_billing_invoices')->count(), DB::table('production_membership_billing_observations')->count()]);
        $this->assertSame(0, DB::table('production_membership_credit_events')->count());
    }

    public function test_a_binding_refusal_under_an_identity_the_binding_already_owns_is_still_appended(): void
    {
        $binding = F::binding();
        $first = (new BillingReconciliation(new RehearsalBillingGateway(F::graph())))->retrieve($binding['id'], F::INVOICE);
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(self::AT + 5));
        $refused = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['invoice' => ['customer' => 'cus_FOREIGNSYNTHETIC']]))))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['refused', 2, $first['seal'], $first['invoice_id']], [$refused['outcome'], $refused['sequence'], $refused['prior_seal'], $refused['invoice_id']]);
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
    }

    public function test_a_refusal_unrelated_to_the_binding_graph_still_claims_the_identity_and_is_recorded(): void
    {
        $binding = F::binding();
        $refused = (new BillingReconciliation(new RehearsalBillingGateway(F::graph(['invoice' => ['currency' => 'usd']]))))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['refused', 1], [$refused['outcome'], $refused['sequence']]);
        $this->assertSame(1, DB::table('production_membership_billing_invoices')->count());
    }

    public function test_a_forged_seal_or_payload_breaks_the_chain_audit(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        $settled = (new BillingReconciliation(new RehearsalBillingGateway(F::graph()), $ledger))->retrieve($binding['id'], F::INVOICE);
        // A structurally valid next row (triggers admit it) whose seal is not the canonical row hash.
        $forged = [...$settled, 'id' => (string) Str::uuid(), 'sequence' => 2, 'prior_seal' => $settled['seal'], 'seal' => hash('sha256', 'forged')];
        DB::connection()->getPdo()->prepare('INSERT INTO '.(new BillingSchema)->table(BillingSchema::TABLES[2]).' ('.implode(',', array_keys($forged))
            .') VALUES ('.implode(',', array_fill(0, count($forged), '?')).')')->execute(array_values($forged));
        foreach ([fn () => $ledger->observations($settled['invoice_id']), fn () => $ledger->currentSettled($settled['invoice_id'], self::AT)] as $read) {
            try {
                $read();
                $this->fail('A forged observation must not be read as evidence.');
            } catch (BillingException $error) {
                $this->assertSame('tampered_ledger', $error->reason);
            }
        }
    }

    public function test_binding_payload_must_match_its_sealed_hashes_and_the_configured_account(): void
    {
        $binding = F::binding();
        $ledger = new BillingLedger;
        foreach ([['account_ref' => 'acct_FOREIGNSYNTHETIC'], ['mode' => 'live']] as $change) {
            try {
                $ledger->binding($binding['id'], [...$this->configuration(), ...$change]);
                $this->fail('A binding is only valid for its own configured account and mode.');
            } catch (BillingException $error) {
                $this->assertSame('binding_mismatch', $error->reason);
            }
        }
        try {
            $ledger->binding((string) Str::uuid(), $this->configuration());
            $this->fail('An absent binding is not adopted.');
        } catch (BillingException $error) {
            $this->assertSame('binding_absent', $error->reason);
        }
    }

    public function test_verdict_values_cannot_claim_settlement_without_facts(): void
    {
        $this->expectException(BillingException::class);
        new BillingVerdict('settled', null, ['amount_minor' => F::AMOUNT]);
    }

    private function configuration(): array
    {
        return (new BillingPolicy)->current();
    }
}
