<?php

namespace Tests\Feature;

use App\Domain\Commerce\ComparePricingSettlement;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PricingSettlementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
    }

    private function pricing(?string $mode = 'fixed_test', bool $twoLines = false): QuotePricing
    {
        PricingFixtures::configure($mode === null ? null : PricingFixtures::policy($mode));
        $items = QuoteFixtures::selection()['items'];
        if ($twoLines) {
            $items = [...$items, ...QuoteFixtures::selection()['items']];
        }
        $quote = app(CreateQuote::class)->handle(str_repeat('a', 64), 'pricing-comparison', $items);

        return app(PriceQuote::class)->create($quote->public_id, str_repeat('a', 64));
    }

    public function test_fixed_amounts_match_exactly_and_evidence_hashes_are_reproducible(): void
    {
        $pricing = $this->pricing();
        $observed = PricingFixtures::observation($pricing);
        $this->assertSame(375, $observed['tax_minor']);
        $this->assertSame(5374, $observed['total_minor']);
        $report = app(ComparePricingSettlement::class)->handle($pricing, $observed);
        $this->assertTrue($report['amounts_match']);
        $this->assertNull($report['reason']);
        $this->assertSame(CanonicalJson::hash($observed), $report['observation_hash']);
        $this->assertSame($report, app(ComparePricingSettlement::class)->handle($pricing, $observed));
        $this->assertDatabaseCount('quote_pricings', 1);
    }

    public function test_changed_identity_amounts_extra_fees_and_noninteger_values_fail(): void
    {
        $pricing = $this->pricing();
        $observed = PricingFixtures::observation($pricing);
        foreach ([
            ['schema_version', '1'], ['pricing_id', 'another'], ['quote_id', 'another'], ['policy_hash', str_repeat('a', 64)],
            ['provider', 'other'], ['account', 'acct_OTHER'], ['livemode', true], ['currency', 'EUR'],
            ['subtotal_minor', 5000], ['discount_minor', 1], ['tax_minor', 374], ['total_minor', 5375], ['shipping_minor', 0],
            ['tax_minor', 375.0], ['tax_minor', '375'], ['tax_minor', -1], ['total_minor', 9007199254740992],
            ['lines.0.quantity', 2], ['lines.0.base_minor', 5000], ['lines.0.discount_minor', 1],
            ['lines.0.tax_basis_minor', 4998], ['lines.0.tax_minor', 374], ['lines.0.total_minor', 5375],
            ['lines.0.offer_revision_id', '1'], ['lines.0.extra', true], ['tax_calculation_id', 'unrequested'],
        ] as [$path, $value]) {
            $changed = $observed;
            Arr::set($changed, $path, $value);
            $report = app(ComparePricingSettlement::class)->handle($pricing, $changed);
            $this->assertFalse($report['amounts_match'], $path.' was accepted.');
            $this->assertNotNull($report['reason']);
        }
        $this->assertFalse(app(ComparePricingSettlement::class)->handle($pricing, [])['amounts_match']);
    }

    public function test_line_order_can_change_but_duplicates_omissions_and_substitutions_cannot(): void
    {
        $pricing = $this->pricing(twoLines: true);
        $observed = PricingFixtures::observation($pricing);
        $observed['lines'] = array_reverse($observed['lines']);
        $this->assertTrue(app(ComparePricingSettlement::class)->handle($pricing, $observed)['amounts_match']);
        foreach ([[$observed['lines'][0]], [$observed['lines'][0], $observed['lines'][0]], []] as $lines) {
            $this->assertFalse(app(ComparePricingSettlement::class)->handle($pricing, [...$observed, 'lines' => $lines])['amounts_match']);
        }
        $observed['lines'][0]['offer_revision_id'] = 999999;
        $this->assertFalse(app(ComparePricingSettlement::class)->handle($pricing, $observed)['amounts_match']);
    }

    public function test_provider_tax_requires_separate_complete_matching_calculation_and_captured_cap(): void
    {
        $pricing = $this->pricing('provider_calculated');
        $before = CanonicalJson::encode($pricing->snapshot);
        $observed = PricingFixtures::observation($pricing, 321);
        $tax = PricingFixtures::taxResult($observed);
        $comparison = app(ComparePricingSettlement::class);
        $this->assertSame('tax_evidence_required', $comparison->handle($pricing, $observed)['reason']);
        $matched = $comparison->handle($pricing, $observed, $tax);
        $this->assertTrue($matched['amounts_match']);
        $this->assertSame(CanonicalJson::hash($tax), $matched['tax_result_hash']);
        foreach ([['id', 'taxcalc_OTHER'], ['status', 'pending'], ['behavior', 'inclusive'], ['account', 'acct_OTHER'],
            ['policy_hash', str_repeat('b', 64)], ['quote_id', 'other'], ['livemode', true], ['lines.0.tax_minor', 322], ['lines', []]] as [$path, $value]) {
            $changed = $tax;
            Arr::set($changed, $path, $value);
            $this->assertFalse($comparison->handle($pricing, $observed, $changed)['amounts_match'], $path);
        }
        $tooHigh = PricingFixtures::observation($pricing, 501); // ceil(4999 * 10%) = 500, a captured test ceiling only.
        $this->assertSame('tax_limit_exceeded', $comparison->handle($pricing, $tooHigh, PricingFixtures::taxResult($tooHigh))['reason']);
        $this->assertSame($before, CanonicalJson::encode($pricing->refresh()->snapshot));
        $this->assertNull($pricing->snapshot['tax_minor']);
    }

    public function test_unresolved_tax_and_production_scope_cannot_match(): void
    {
        $pricing = $this->pricing(null);
        $report = app(ComparePricingSettlement::class)->handle($pricing, PricingFixtures::observation($pricing, 0));
        $this->assertFalse($report['amounts_match']);
        $this->assertSame('unresolved_tax', $report['reason']);
        $this->assertFalse($pricing->snapshot['payable']);
    }

    public function test_production_cannot_use_a_test_pricing_comparison(): void
    {
        $pricing = $this->pricing();
        $observed = PricingFixtures::observation($pricing);
        $this->app->instance('env', 'production');
        $report = app(ComparePricingSettlement::class)->handle($pricing, $observed);
        $this->assertFalse($report['amounts_match']);
        $this->assertSame('test_scope_required', $report['reason']);
    }

    public function test_comparison_retains_historical_policy_and_does_not_claim_current_eligibility(): void
    {
        $pricing = $this->pricing();
        $observed = PricingFixtures::observation($pricing);
        $this->travelTo($pricing->expires_at->addHour());
        PricingFixtures::configure(null);
        // Late payment reconciliation needs original math. Lifetime/order/inventory are separate WP-07 checks.
        $this->assertTrue(app(ComparePricingSettlement::class)->handle($pricing, $observed)['amounts_match']);
        $this->assertFalse($pricing->snapshot['payable']);
        $this->assertDatabaseCount('quote_pricings', 1);
    }

    public function test_invalid_stored_pricing_never_produces_a_match(): void
    {
        $pricing = $this->pricing();
        $observed = PricingFixtures::observation($pricing);
        $pricing->snapshot_hash = str_repeat('a', 64);
        $report = app(ComparePricingSettlement::class)->handle($pricing, $observed);
        $this->assertFalse($report['amounts_match']);
        $this->assertSame('invalid_pricing_evidence', $report['reason']);
    }
}
