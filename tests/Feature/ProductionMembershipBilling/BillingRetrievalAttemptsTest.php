<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
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
}
