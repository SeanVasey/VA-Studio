<?php

namespace Tests\Feature;

use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReadQuoteDisclosure;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\Support\PricingFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class QuotePricingTest extends TestCase
{
    use RefreshDatabase;

    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        PricingFixtures::configure(null);
    }

    private function quote(?array $items = null): Quote
    {
        return app(CreateQuote::class)->handle(self::OWNER, (string) Str::uuid(), $items ?? QuoteFixtures::selection()['items']);
    }

    private function rejects(string $code, callable $action): void
    {
        try {
            $action();
            $this->fail('Pricing unexpectedly succeeded.');
        } catch (QuoteException $exception) {
            $this->assertSame($code, $exception->errorCode);
        }
    }

    public function test_missing_tax_is_retained_as_unknown_without_mutating_the_quote(): void
    {
        $quote = $this->quote();
        $before = CanonicalJson::encode($quote->snapshot);
        $this->rejects('PRICING_NOT_FOUND', fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        $this->assertSame('unresolved', $pricing->snapshot['tax_status']);
        $this->assertNull($pricing->snapshot['tax_policy']);
        $this->assertNull($pricing->snapshot['tax_minor']);
        $this->assertNull($pricing->snapshot['total_minor']);
        $this->assertNull($pricing->snapshot['lines'][0]['tax_minor']);
        $this->assertSame(0, $pricing->snapshot['discount_minor']);
        $this->assertSame(4999, $pricing->snapshot['tax_basis_minor']);
        $this->assertFalse($pricing->snapshot['payable']);
        $this->assertSame($pricing->public_id, app(PriceQuote::class)->create($quote->public_id, self::OWNER)->public_id);
        $this->assertSame($pricing->snapshot_hash, app(PriceQuote::class)->read($quote->public_id, self::OWNER)->snapshot_hash);
        $this->assertSame($before, CanonicalJson::encode($quote->refresh()->snapshot));
        $this->assertDatabaseCount('quote_pricings', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.priced')->count());
    }

    public function test_fixed_test_tax_rounds_each_line_and_binds_exact_disclosures(): void
    {
        $policy = PricingFixtures::policy();
        $policy['tax']['rate_bps'] = 5000;
        PricingFixtures::configure($policy);
        $first = QuoteFixtures::selection(1);
        $second = QuoteFixtures::selection(1);
        $quote = $this->quote([...$second['items'], ...$first['items']]);
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        // Two independently rounded half-cent taxes are two cents, not a basket-rounded cent.
        $this->assertSame(2, $pricing->snapshot['subtotal_minor']);
        $this->assertSame(2, $pricing->snapshot['tax_minor']);
        $this->assertSame(4, $pricing->snapshot['total_minor']);
        $this->assertSame('fixed_test', $pricing->snapshot['tax_status']);
        foreach ($pricing->snapshot['lines'] as $line) {
            $this->assertSame(1, $line['tax_minor']);
            $this->assertSame(5000, $line['rounding']['remainder']);
            $disclosure = app(ReadQuoteDisclosure::class)->handle($quote->public_id, self::OWNER, (string) $line['offer_revision_id']);
            $this->assertSame($disclosure['disclosureHash'], $line['disclosure_hash']);
        }
        $this->assertSame(CanonicalJson::hash($policy), $pricing->snapshot['policy_hash']);
        $data = app(PricingSnapshot::class)->present($pricing);
        $hash = $data['pricingHash'];
        unset($data['pricingHash']);
        $this->assertSame(CanonicalJson::hash($data), $hash);
        $this->assertNull($quote->refresh()->snapshot['tax_minor']);
    }

    public function test_zero_rate_is_distinct_from_provider_pending_and_unresolved(): void
    {
        $policy = PricingFixtures::policy();
        $policy['tax']['rate_bps'] = 0;
        PricingFixtures::configure($policy);
        $quote = $this->quote();
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        $this->assertSame(0, $pricing->snapshot['tax_minor']);
        $this->assertSame(4999, $pricing->snapshot['total_minor']);
        PricingFixtures::configure(PricingFixtures::policy('provider_calculated'));
        $next = $this->quote($quote->request);
        $pending = app(PriceQuote::class)->create($next->public_id, self::OWNER);
        $this->assertSame('provider_pending', $pending->snapshot['tax_status']);
        $this->assertNull($pending->snapshot['tax_minor']);
        $this->assertNull($pending->snapshot['total_minor']);
        $this->assertSame(1000, $pending->snapshot['tax_policy']['tax']['max_rate_bps']);
    }

    public function test_policy_end_clamps_pricing_expiry_and_replay_never_extends_it(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = PricingFixtures::policy();
        $policy['effective_until'] = now()->addSeconds(30)->toIso8601ZuluString();
        PricingFixtures::configure($policy);
        $quote = $this->quote();
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        $this->assertSame($policy['effective_until'], $pricing->expires_at->toIso8601ZuluString());
        $this->travelTo($pricing->expires_at->subSecond());
        $this->assertSame($pricing->public_id, app(PriceQuote::class)->create($quote->public_id, self::OWNER)->public_id);
        $this->travelTo($pricing->expires_at);
        $this->rejects('PRICING_EXPIRED', fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $this->assertDatabaseCount('quote_pricings', 1);
    }

    public function test_policy_changes_or_removal_require_a_new_quote_and_preserve_old_evidence(): void
    {
        $policy = PricingFixtures::policy();
        PricingFixtures::configure($policy);
        $quote = $this->quote();
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        $hash = $pricing->snapshot_hash;
        $policy['tax']['rate_bps']++;
        // Even reusing the textual key/version cannot silently replace captured policy contents.
        PricingFixtures::configure($policy);
        $this->rejects('PRICING_CHANGED', fn () => app(PriceQuote::class)->create($quote->public_id, self::OWNER));
        PricingFixtures::configure(null);
        $this->rejects('PRICING_CHANGED', fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $this->assertSame($hash, $pricing->refresh()->snapshot_hash);
        PricingFixtures::configure(PricingFixtures::policy());
        $this->assertSame($hash, app(PriceQuote::class)->read($quote->public_id, self::OWNER)->snapshot_hash);
    }

    public function test_owner_is_checked_before_pricing_existence_or_configuration(): void
    {
        $quote = $this->quote();
        config(['commerce.test_pricing_policy' => 'bad private configuration']);
        $this->rejects('QUOTE_NOT_FOUND', fn () => app(PriceQuote::class)->create($quote->public_id, str_repeat('b', 64)));
        $this->rejects('QUOTE_NOT_FOUND', fn () => app(PriceQuote::class)->read($quote->public_id, str_repeat('b', 64)));
        $this->assertDatabaseCount('quote_pricings', 0);
    }

    public function test_unavailable_offer_cannot_reuse_a_pricing_record(): void
    {
        $fixture = QuoteFixtures::selection();
        $quote = $this->quote($fixture['items']);
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        app(DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $this->rejects('SELECTION_CHANGED', fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $this->rejects('SELECTION_CHANGED', fn () => app(PriceQuote::class)->create($quote->public_id, self::OWNER));
        $this->assertSame($pricing->snapshot_hash, $pricing->refresh()->snapshot_hash);
    }

    public function test_expiry_during_a_lock_wait_cannot_return_current_pricing(): void
    {
        $policy = PricingFixtures::policy();
        $policy['effective_until'] = now()->addMinute()->startOfSecond()->toIso8601ZuluString();
        PricingFixtures::configure($policy);
        $quote = $this->quote();
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        $waited = false;
        DB::listen(function ($query) use (&$waited, $pricing) {
            if (! $waited && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'tracks')) {
                $waited = true;
                $this->travelTo($pricing->expires_at);
            }
        });
        $this->rejects('PRICING_EXPIRED', fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $this->assertTrue($waited);
    }

    public function test_pricing_is_immutable_through_models_bulk_sql_and_duplicate_insert(): void
    {
        $quote = $this->quote();
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        foreach ([fn () => $pricing->update(['snapshot_hash' => str_repeat('b', 64)]), fn () => $pricing->delete()] as $operation) {
            try { $operation(); $this->fail('Model mutated pricing.'); } catch (LogicException) { $this->assertTrue(true); }
        }
        $row = $pricing->refresh()->getAttributes();
        unset($row['id']);
        $row['public_id'] = (string) Str::uuid();
        foreach ([
            fn () => DB::table('quote_pricings')->where('id', $pricing->id)->update(['snapshot_hash' => str_repeat('b', 64)]),
            fn () => DB::table('quote_pricings')->where('id', $pricing->id)->delete(),
            fn () => DB::table('quote_pricings')->insert($row),
        ] as $operation) {
            try { DB::transaction($operation); $this->fail('Database mutated or duplicated pricing.'); } catch (QueryException) { $this->assertTrue(true); }
        }
        $this->assertDatabaseCount('quote_pricings', 1);
        $this->assertSame(CanonicalJson::hash($pricing->snapshot), $pricing->snapshot_hash);
    }

    public function test_rehashed_tampering_still_fails_recalculation_and_quote_binding(): void
    {
        PricingFixtures::configure(PricingFixtures::policy());
        $quote = $this->quote();
        $pricing = app(PriceQuote::class)->create($quote->public_id, self::OWNER);
        $snapshot = $pricing->snapshot;
        $snapshot['total_minor']--;
        $pricing->snapshot = $snapshot;
        $pricing->snapshot_hash = CanonicalJson::hash($snapshot);
        $this->expectException(InvalidArgumentException::class);
        app(PricingSnapshot::class)->verify($pricing, $quote);
    }
}
