<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Grants\Paid\PaidGrantDocuments;
use App\Domain\Grants\Paid\PaidGrantException;
use App\Domain\Grants\Paid\PaidGrantFiles;
use App\Domain\Grants\Paid\PaidGrantRecords;
use App\Domain\Grants\Paid\PaidGrants;
use App\Domain\Grants\PaidTax\PaidTaxException;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaidTaxGrantFixtures;
use Tests\Support\ProductionCheckoutFixtures as F;
use Tests\Support\ProductionCheckoutGatewayFixture;
use Tests\TestCase;

/** Both genuine rehearsal producers and current canonical V1 preparation, with the forward Tax schema installed. */
class PaidTaxFamilyCompatibilityTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use PaidTaxGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->preparePaidTaxFixture();
        config(['production_checkout.committed_read_receipts_enabled' => true,
            'production_checkout.committed_read_receipt_version' => 'production-checkout-committed-read-v1',
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true,
            'paid-grants.rehearsal_enabled' => true,
            'paid-grants.delivery_policy' => ['schema_version' => 1, 'version' => 'explicit-synthetic-delivery-v1', 'purpose' => 'paid-original-delivery',
                'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
    }

    public function test_real_v1_and_tax_orders_for_one_buyer_remain_separate_and_v1_original_bytes_are_unchanged(): void
    {
        $tax = $this->paidTaxOrder();
        $buyer = $tax['buyer'];
        $access = $tax['access'];
        $catalog = F::catalog();
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-family-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $catalog['items'],
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:family-buyer-qualification',
                'source_sha256' => hash('sha256', 'NONBINDING FAMILY COMPATIBILITY QUALIFICATION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-family-qualification', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-family-review');
        $legacy = $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-family-accept');
        $gateway = new ProductionCheckoutGatewayFixture;
        $hosted = new HostedCheckout($access, $gateway);
        $hosted->initiate($buyer['principal'], $buyer['user'], $legacy['orderId']);
        $gateway->paid = true;
        $this->assertSame('verified', $hosted->reconcile($buyer['principal'], $buyer['user'], $legacy['orderId'])['paymentStatus']);
        foreach ([[new PaidGrants, $tax['order']['orderId'], PaidGrantException::class],
            [new PaidTaxGrants, $legacy['orderId'], PaidTaxException::class]] as [$consumer, $wrongId, $exception]) {
            $failure = null;
            try {
                $consumer->finalize($buyer['principal'], $buyer['user'], $wrongId);
            } catch (\Throwable $error) {
                $failure = $error;
            }
            $this->assertInstanceOf($exception, $failure, 'A consumer accepted another genuine producer family.');
            $this->assertContains($failure->status, [404, 409]);
        }
        $this->assertDatabaseCount('paid_order_origins', 0);
        $this->assertDatabaseCount('paid_tax_order_origins', 0);
        $v1 = (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $legacy['orderId']);
        $this->assertSame('pending', $v1['lines'][0]['documentStatus']);
        $complete = (new PaidGrantDocuments)->prepare($v1['id'], $buyer['principal'], $buyer['user']);
        $this->assertTrue($complete['fulfilled']);
        $original = (array) DB::table('paid_originals')->sole();
        $artifact = PaidGrantRecords::decode($original)['artifact'];
        $bytes = (new PaidGrantFiles)->verify($artifact);
        $taxProjection = (new PaidTaxGrants)->finalize($buyer['principal'], $buyer['user'], $tax['order']['orderId']);
        $this->assertFalse($taxProjection['fulfilled']);
        $this->assertNotSame($complete['id'], $taxProjection['id']);
        $this->assertSame($complete, (new PaidGrants)->finalize($buyer['principal'], $buyer['user'], $legacy['orderId']));
        $this->assertSame($complete, (new PaidGrantDocuments)->prepare($v1['id'], $buyer['principal'], $buyer['user']));
        $this->assertSame($original, (array) DB::table('paid_originals')->sole());
        $this->assertSame($bytes, (new PaidGrantFiles)->verify($artifact));
        foreach (['paid_order_origins', 'paid_tax_order_origins', 'paid_originals', 'paid_fulfillments'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('paid_tax_originals', 0);
        $this->assertDatabaseCount('paid_tax_fulfillments', 0);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
