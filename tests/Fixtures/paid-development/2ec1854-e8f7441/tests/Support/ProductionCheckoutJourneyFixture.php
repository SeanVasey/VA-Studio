<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionCheckout\ApproveExemptionAuthority;
use App\Domain\Commerce\ProductionCheckout\HostedCheckout;
use App\Domain\Commerce\ProductionCheckout\ProductionCheckout;
use App\Domain\Commerce\ProductionCheckout\TaxExemptions;
use App\Domain\Customers\ProductionCustomerAccess;
use Carbon\CarbonImmutable;
use Tests\Support\ProductionCheckoutFixtures as F;

/** Actual shared enrollment/source/catalog graph; provider evidence remains rehearsal only. */
trait ProductionCheckoutJourneyFixture
{
    use ProductionIdentityFixture;

    protected function payable(bool $accept = true): array
    {
        $catalog = F::catalog();
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $authority = app(ApproveExemptionAuthority::class)->approve($catalog['candidate']->id, F::exemptionPolicy($catalog), 'synthetic-owner-policy', $catalog['actor']);
        $basis = (new TaxExemptions($access))->qualify($buyer['principal'], $buyer['user'], $authority['public_id'], $catalog['items'],
            ['qualified_exemption_confirmed' => true, 'reference' => 'synthetic:buyer-bound-qualification', 'source_sha256' => hash('sha256', 'NONBINDING SYNTHETIC BUYER EXEMPTION'),
                'effective_from' => CarbonImmutable::now('UTC')->subDay()->format('Y-m-d\TH:i:s\Z'),
                'effective_until' => CarbonImmutable::now('UTC')->addDays(30)->format('Y-m-d\TH:i:s\Z')], 'synthetic-qualified-buyer', $catalog['actor']);
        $checkout = new ProductionCheckout($access);
        $review = $checkout->review($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $basis['public_id'], ['legalName' => 'Declared synthetic buyer'], 'synthetic-review');
        $order = $accept ? $checkout->accept($buyer['principal'], $buyer['user'], $review['reviewId'], $review['reviewHash'], true, 'synthetic-accept') : null;
        $gateway = new ProductionCheckoutGatewayFixture;
        $hosted = new HostedCheckout($access, $gateway);

        return compact('catalog', 'buyer', 'access', 'authority', 'basis', 'checkout', 'review', 'order', 'gateway', 'hosted');
    }
}
