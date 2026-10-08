<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionPolicy\PrepareProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\ReviewProductionTrackCapabilities;
use App\Domain\Commerce\ProductionPolicy\SaveProductionTrackCapabilities;
use App\Domain\Commerce\ProductionTaxCheckout\ProductionTaxCheckout;
use App\Domain\Customers\ProductionCustomerAccess;

/**
 * Synthetic contract inputs only. Every account, origin, tax behavior, rate ceiling and provider figure here is a
 * NONBINDING rehearsal value; none is a merchant, tax-registration, jurisdiction or payment fact.
 */
trait ProductionTaxCheckoutFixtures
{
    use ProductionIdentityFixture;

    public const SYNTHETIC_PROVIDER = 'synthetic-tax-transport-v1';

    protected static function configureTax(?string $provider = null): void
    {
        if (! app()->environment('testing')) {
            throw new \LogicException('Tax checkout fixtures require testing.');
        }
        config(['production-tax-checkout.enabled' => true, 'production-tax-checkout.provider' => $provider,
            'production-tax-checkout.funds_mode' => 'test', 'production-tax-checkout.account_id' => 'acct_SYNTHETIC',
            'production-tax-checkout.return_origin' => 'https://review.invalid']);
    }

    protected static function taxMachine(string $behavior = 'exclusive', int $ceiling = 2500): array
    {
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $machine['version'] = 'synthetic-tax-checkout-machine-v1';
        $machine['choices']['tax_calculation'] = ['strategy' => 'provider_calculated', 'behavior' => $behavior, 'maximum_rate_bps' => $ceiling, 'rounding' => 'provider_exact'];
        $machine['choices']['reservation_and_exclusives']['late_time_basis'] = 'application_verified_observation_time';
        $machine['choices']['reservation_and_exclusives']['reservation_seconds'] = 7200;
        $machine['choices']['reservation_and_exclusives']['provider_lifetime_seconds'] = 3600;

        return $machine;
    }

    /** Actual catalog/source lifecycle with a provider-calculated tax candidate. */
    protected function taxCatalog(string $behavior = 'exclusive', int $ceiling = 2500): array
    {
        self::configureTax();
        $fixture = ProductionTrackPreparationFixtures::prepared();
        $machine = self::taxMachine($behavior, $ceiling);
        $candidate = app(SaveProductionTrackCapabilities::class)->applyReviewed(
            app(PrepareProductionTrackCapabilities::class)->review($fixture['candidate'], $fixture['source'], $machine, $fixture['actor']), $fixture['actor']);
        app(ReviewProductionTrackCapabilities::class)->applyReviewed(app(ReviewProductionTrackCapabilities::class)->review($candidate, $fixture['reviewer']),
            ProductionTrackPreparationFixtures::reference('software_choices_reviewed'), $fixture['reviewer']);

        return array_replace($fixture, compact('machine', 'candidate'));
    }

    /** Enrolled buyer, previewed and accepted pre-tax order, and a recording transport that is NOT yet admitted. */
    protected function taxOrder(string $behavior = 'exclusive', int $ceiling = 2500): array
    {
        $catalog = $this->taxCatalog($behavior, $ceiling);
        $buyer = $this->enrollThroughLocalSmtp();
        $access = new ProductionCustomerAccess;
        $transport = new RecordingTaxCheckoutTransport;
        $checkout = new ProductionTaxCheckout($access, $transport);
        $preview = $checkout->preview($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items']);
        $order = $checkout->order($buyer['principal'], $buyer['user'], $catalog['candidate']->id, $catalog['items'], $preview['previewHash'], true,
            ['legalName' => 'Declared synthetic buyer'], 'synthetic-tax-order');

        return compact('catalog', 'buyer', 'access', 'transport', 'checkout', 'preview', 'order');
    }
}
