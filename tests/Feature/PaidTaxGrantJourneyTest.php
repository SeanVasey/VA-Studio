<?php

namespace Tests\Feature;

use App\Domain\Customers\ProductionIdentity\IdentityMigrationOwnership;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Grants\PaidTax\PaidTaxGrants;
use App\Domain\Grants\PaidTax\PaidTaxProjectionRead;
use App\Domain\Grants\PaidTax\PaidTaxRecords;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\TestCase;

/** Actual local SMTP enrollment and retained synthetic Tax payment; no host/provider acceptance claim. */
class PaidTaxGrantJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionTaxCheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => 'identity-historical-committed-receipt-v1',
            'production-tax-checkout.committed_read_receipts_enabled' => true,
            'paid-tax-grants.rehearsal_enabled' => true,
            'paid-tax-grants.delivery_policy' => ['schema_version' => 2, 'version' => 'explicit-synthetic-tax-delivery-v2',
                'purpose' => 'paid-tax-original-delivery', 'provenance' => 'synthetic_rehearsal', 'max_downloads' => 3, 'authorization_seconds' => 60]]);
        Queue::fake();
    }

    protected function paid(string $behavior = 'exclusive', int $tax = 437): array
    {
        $f = $this->taxOrder($behavior);
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->lineTax = $tax;
        $f['transport']->paid = true;
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);

        return $f;
    }

    public static function amounts(): array
    {
        return ['exclusive' => ['exclusive', 437, 5436], 'inclusive' => ['inclusive', 437, 4999], 'zero tax' => ['exclusive', 0, 4999]];
    }

    #[DataProvider('amounts')]
    public function test_authenticated_tax_origin_retains_original_nested_amounts_and_replays_once(string $behavior, int $tax, int $total): void
    {
        $f = $this->paid($behavior, $tax);
        $projectionRead = PaidTaxProjectionRead::begin();
        $first = (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId'], $projectionRead);
        $projectionRead->proveBeforeBytes();
        $this->assertSame([2, 'paid-tax-license-grant', false, $f['order']['orderId']],
            [$first['schemaVersion'], $first['purpose'], $first['fulfilled'], $first['orderId']]);
        $this->assertSame([4999, $tax, $total, $behavior], [$first['lines'][0]['tax']['line_subtotal_minor'],
            $first['lines'][0]['tax']['line_tax_minor'], $first['lines'][0]['tax']['line_total_minor'], $first['lines'][0]['tax']['tax_behavior']]);
        $this->assertSame([], $first['lines'][0]['files']);
        $this->assertSame($first, (new PaidTaxGrants)->finalize($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        foreach (['paid_tax_order_origins', 'paid_tax_grant_origins', 'paid_tax_document_work'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        foreach (['paid_order_origins', 'paid_grant_origins', 'paid_originals', 'paid_tax_originals', 'paid_tax_fulfillments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $source = PaidTaxRecords::decode((array) DB::table('paid_tax_grant_origins')->sole())['source'];
        $this->assertSame($first['orderId'], $source['order_id']);
        $this->assertSame($first['lines'][0]['tax'], $source['tax']);
        foreach (['payment_id', 'payment_hash', 'amounts', 'line_amount_minor', 'line_tax_minor'] as $v1Field) {
            $this->assertArrayNotHasKey($v1Field, $source);
        }
        // Actual original inspector compatibility, including the new ordinary user/account FK consumers.
        $ownership = (new IdentityMigrationOwnership)->inspect(DB::getPdo(), DB::getDriverName());
        $this->assertNotEmpty($ownership);
    }
}
