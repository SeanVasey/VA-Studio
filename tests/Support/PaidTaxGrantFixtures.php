<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use Illuminate\Support\Facades\Queue;

/** Genuine retained synthetic Tax checkout fixture; no production/host/provider authority. */
trait PaidTaxGrantFixtures
{
    use ProductionTaxCheckoutFixtures;

    protected function secondPaidTaxOrder(array $first, string $session, string $payment): array
    {
        $catalog = $first['catalog'];
        $buyer = $first['buyer'];
        $access = new ProductionCustomerAccess;
        $transport = new PaidTaxDistinctTransportFixture($session, $payment);
        $checkout = new ProductionTaxCheckout($access, $transport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);
        $order = $checkout->order($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $preview['previewHash'], true,
            ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-second-order');
        $checkout->initiate($buyer['principal'], $buyer['user'], $order['orderId']);
        $transport->paid = true;
        $this->assertSame('verified', $checkout->reconcile($buyer['principal'], $buyer['user'], $order['orderId'])['paymentStatus']);

        return compact('catalog', 'buyer', 'access', 'transport', 'checkout', 'preview', 'order');
    }

    protected function preparePaidTaxFixture(): void
    {
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

    protected function paidTaxOrder(string $behavior = 'exclusive', int $tax = 437, bool $twoLines = false): array
    {
        if ($twoLines) {
            $catalog = $this->taxCatalog($behavior);
            $second = QuoteFixtures::selection(3499);
            $items = [...$catalog['items'], ...$second['items']];
            $buyer = $this->enrollThroughLocalSmtp();
            $access = new ProductionCustomerAccess;
            $transport = new RecordingTaxCheckoutTransport;
            $checkout = new ProductionTaxCheckout($access, $transport);
            $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items);
            $order = $checkout->order($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $items, $preview['previewHash'], true,
                ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-two-lines');
            $f = compact('catalog', 'second', 'buyer', 'access', 'transport', 'checkout', 'preview', 'order');
        } else {
            $f = $this->taxOrder($behavior);
        }
        self::configureTax(self::SYNTHETIC_PROVIDER);
        $f['checkout']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['transport']->lineTax = $tax;
        $f['transport']->paid = true;
        $paid = $f['checkout']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);

        return $f;
    }
}
