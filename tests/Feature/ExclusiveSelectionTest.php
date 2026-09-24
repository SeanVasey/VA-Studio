<?php

namespace Tests\Feature;

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\ComparePricingSettlement;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReadQuote;
use App\Domain\Commerce\ReservePricedQuote;
use App\Support\CanonicalJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveSelectionFixtures as F;
use Tests\Support\InventoryFixtures;
use Tests\Support\PricingFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ExclusiveSelectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure(); PricingFixtures::configure(null);
    }

    private function rejected(array $codes, callable $operation): void
    {
        try { $operation(); $this->fail('Operation unexpectedly succeeded.'); }
        catch (QuoteException $error) { $this->assertContains($error->errorCode, $codes); }
    }

    private function promotion(array $ids): array
    {
        sort($ids); $policy = PromotionFixtures::policy(['eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => $ids]]);
        PromotionFixtures::configure([$policy]);

        return $policy;
    }

    public function test_http_selection_disclosure_and_pricing_are_owned_private_versioned_and_non_payable(): void
    {
        $f = F::active(); $key = (string) Str::uuid();
        $response = $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => $key])->assertOk();
        $quote = $response->json('quote'); $stored = Quote::sole(); $hash = $stored->snapshot_hash;
        $this->assertSame(2, $stored->snapshot['schema_version']);
        // MySQL JSON storage may reorder object keys; all keys, values and types must still match.
        $this->assertSame(CanonicalJson::encode(F::policy()), CanonicalJson::encode($stored->snapshot['selection_policy']));
        $this->assertCount(1, $stored->snapshot['scope_bindings']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->postJson('/quotes', ['items' => $f['items']], ['Idempotency-Key' => $key])->assertOk()->assertJsonPath('quote.id', $quote['id']);
        $url = $quote['items'][0]['licenseUrl'];
        $disclosure = $this->getJson($url)->assertOk()->assertJsonPath('disclosureSchema', 2)
            ->assertJsonPath('type', 'exclusive')->assertJsonPath('testOnly', true)->assertHeader('Cache-Control', 'no-store, private');
        $data = $disclosure->json(); $digest = $data['disclosureHash']; unset($data['disclosureHash']);
        $this->assertSame(CanonicalJson::hash($data), $digest);
        $priced = $this->call('POST', '/quotes/'.$quote['id'].'/pricing', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk()
            ->assertJsonPath('pricing.pricingSchema', 3)->assertJsonPath('pricing.payable', false)
            ->assertJsonPath('pricing.testOnly', true)->assertJsonPath('pricing.taxMinor', null)->assertJsonPath('pricing.totalMinor', null);
        $this->assertDatabaseCount('quote_pricings', 1); $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame('held', InventoryReservation::sole()->state);
        $audits = DB::table('audit_events')->count();
        $this->getJson('/quotes/'.$quote['id'].'/pricing')->assertOk()->assertExactJson($priced->json());
        $this->getJson($url)->assertOk()->assertExactJson($disclosure->json());
        $this->assertSame($audits, DB::table('audit_events')->count());
        $this->assertSame($hash, $stored->refresh()->snapshot_hash);
        foreach ([$hash, $stored->owner_key, $f['activation']->snapshot_hash, $f['media']['master_wav']->storage_path] as $private) {
            $response->assertDontSee($private, false); $disclosure->assertDontSee($private, false); $priced->assertDontSee($private, false);
        }
        $this->flushSession();
        $this->getJson('/quotes/'.$quote['id'].'/pricing')->assertNotFound(); $this->getJson($url)->assertNotFound();
    }

    public static function modes(): array { return [['none'], ['fixed_test'], ['provider_calculated']]; }

    #[DataProvider('modes')]
    public function test_pricing_retains_integer_calculations_and_historical_reproduction(string $mode): void
    {
        PricingFixtures::configure($mode === 'none' ? null : PricingFixtures::policy($mode));
        $f = F::active(); $quote = F::quote($f); $pricing = app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $this->assertSame(3, $pricing->snapshot['schema_version']);
        $this->assertSame(123456, $pricing->snapshot['subtotal_minor']);
        $this->assertSame($mode === 'fixed_test' ? 9259 : null, $pricing->snapshot['tax_minor']);
        $this->assertSame($mode === 'fixed_test' ? 132715 : null, $pricing->snapshot['total_minor']);
        $this->assertFalse($pricing->snapshot['payable']);
        $before = CanonicalJson::encode($pricing->snapshot);
        config(['commerce.test_exclusive_selection_policy' => null]); PricingFixtures::configure(null);
        $this->travelTo($quote->expires_at->addDay());
        $this->assertSame($before, CanonicalJson::encode(app(PricingSnapshot::class)->verify($pricing, $quote)));
        $this->rejected(['QUOTE_EXPIRED'], fn () => app(PriceQuote::class)->read($quote->public_id, InventoryFixtures::OWNER));
    }

    public function test_exclusive_discounts_require_explicit_revision_eligibility_and_match_settlement_amounts(): void
    {
        PricingFixtures::configure(PricingFixtures::policy()); $f = F::active(); $quote = F::quote($f);
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        $this->rejected(['PROMOTION_NOT_ELIGIBLE'], fn () => app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC'));
        $this->assertDatabaseCount('quote_pricings', 0); $this->assertDatabaseCount('promotion_uses', 0); $this->assertDatabaseCount('inventory_reservations', 0);
        $this->promotion([$f['revision']->id]);
        $pricing = app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $this->assertSame(500, $pricing->snapshot['discount_minor']);
        $this->assertSame(122956, $pricing->snapshot['tax_basis_minor']);
        $this->assertSame(9222, $pricing->snapshot['tax_minor']);
        $this->assertTrue(app(ComparePricingSettlement::class)->handle($pricing, PromotionFixtures::observation($pricing))['amounts_match']);
        $this->assertSame('held', PromotionUse::sole()->state); $this->assertSame('held', InventoryReservation::sole()->state);
    }

    public function test_mixed_cart_all_non_exclusive_discount_excludes_exclusive_spend_from_minimum_and_allocation(): void
    {
        $exclusive = F::active(); $nonExclusive = InventoryFixtures::selection();
        $quote = F::quote(['items' => [...$exclusive['items'], ...$nonExclusive['items']]]);
        PromotionFixtures::configure([PromotionFixtures::policy(['minimum_subtotal_minor' => 5000])]);
        $this->rejected(['PROMOTION_NOT_ELIGIBLE'], fn () => app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC'));
        PromotionFixtures::configure([PromotionFixtures::policy(['discount' => ['type' => 'percentage', 'rate_bps' => 1000, 'max_discount_minor' => 1000]])]);
        $pricing = app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $lines = collect($pricing->snapshot['lines'])->keyBy('offer_revision_id');
        $this->assertSame(0, $lines[$exclusive['revision']->id]['discount_minor']);
        $this->assertSame(500, $lines[$nonExclusive['revision']->id]['discount_minor']);
        $this->assertDatabaseCount('inventory_claims', 2);
    }

    public function test_unlinked_mixed_lines_and_duplicate_underlying_scopes_cannot_create_a_quote(): void
    {
        $a = F::active(); $b = F::active($a['scope']); $unlinked = QuoteFixtures::selection();
        foreach ([$b['items'], $unlinked['items']] as $otherItems) {
            $this->rejected(['INVENTORY_SCOPE_UNAVAILABLE'], fn () => F::quote(['items' => [...$a['items'], ...$otherItems]]));
        }
        $this->assertDatabaseCount('quotes', 0); $this->assertDatabaseCount('inventory_claims', 0);
    }

    public function test_same_owner_different_quotes_compete_but_owner_replay_keeps_its_exact_hold(): void
    {
        $f = F::active(); $first = F::quote($f); $other = F::quote($f); $prices = app(PriceQuote::class);
        $pricing = $prices->create($first->public_id, InventoryFixtures::OWNER);
        $this->assertSame($pricing->id, $prices->create($first->public_id, InventoryFixtures::OWNER)->id);
        $this->assertSame($pricing->id, $prices->read($first->public_id, InventoryFixtures::OWNER)->id);
        $this->rejected(['SELECTION_CHANGED', 'INVENTORY_UNAVAILABLE'], fn () => $prices->create($other->public_id, InventoryFixtures::OWNER));
        $this->rejected(['INVENTORY_UNAVAILABLE'], fn () => F::quote($f));
        $this->assertDatabaseCount('quote_pricings', 1); $this->assertDatabaseCount('inventory_claims', 1);
        $this->getJson('/api/catalog')->assertJsonCount(0, 'tracks');
        $this->getJson('/tracks/'.$f['track']->slug.'/offers/'.$f['revision']->id.'/license')->assertNotFound();
    }

    public static function cutoff(): array { return [['exclusive_first'], ['non_exclusive_first']]; }

    #[DataProvider('cutoff')]
    public function test_non_exclusive_and_exclusive_holds_enforce_the_cutoff_in_both_directions(string $direction): void
    {
        $f = F::active(); $exclusive = F::quote($f); $legacy = F::quote(['items' => $f['legacy']['items']]);
        $winner = $direction === 'exclusive_first' ? $exclusive : $legacy;
        $loser = $direction === 'exclusive_first' ? $legacy : $exclusive;
        $service = app(ReservePricedQuote::class); $held = $service->hold($winner->public_id, InventoryFixtures::OWNER);
        $attempt = (string) Str::uuid(); $service->beginAttempt($winner->public_id, InventoryFixtures::OWNER, $attempt);
        $this->travelTo($held['reservation']->expires_at->addSecond());
        $this->rejected(['SELECTION_CHANGED', 'INVENTORY_UNAVAILABLE'], fn () => $service->hold($loser->public_id, InventoryFixtures::OWNER));
        $this->assertSame('pending', $held['reservation']->refresh()->state);
        $this->assertSame($attempt, $held['reservation']->attempt_id);
        $this->assertDatabaseCount('quote_pricings', 1); $this->assertDatabaseCount('inventory_reservations', 1);
        $this->assertSame(1, $legacy->snapshot['schema_version']);
    }

    public function test_unlinked_non_exclusive_successor_cannot_escape_a_governed_scope_cutoff(): void
    {
        $f = F::active();
        $offer = app(SaveOfferDraft::class)->handle($f['legacy']['offer'], ['price_minor' => 5001], $f['actor']);
        $revision = app(PublishOffer::class)->handle($offer, $f['actor']);
        $items = $f['legacy']['items']; $items[0]['offerRevisionId'] = $revision->id;
        $this->rejected(['INVENTORY_UNAVAILABLE'], fn () => F::quote(['items' => $items]));
        $this->getJson('/api/catalog')->assertJsonCount(1, 'tracks.0.offers')->assertJsonPath('tracks.0.offers.0.offerRevisionId', (string) $f['revision']->id);
        app(ManageRightsScope::class)->link($f['scope']->id, $revision->id, 'EXPLICIT-SUCCESSOR-LINK', $f['actor']);
        $quote = F::quote(['items' => $items]);
        $this->assertSame(5001, $quote->subtotal_minor);
        $exclusive = F::quote($f); app(PriceQuote::class)->create($exclusive->public_id, InventoryFixtures::OWNER);
        $this->rejected(['SELECTION_CHANGED', 'INVENTORY_UNAVAILABLE'], fn () => app(ReservePricedQuote::class)->hold($quote->public_id, InventoryFixtures::OWNER));
        $this->assertSame(4999, $f['legacy']['revision']->refresh()->price_minor);
    }

    public function test_expired_holds_cannot_be_revived_and_new_quotes_can_reuse_only_unstarted_capacity(): void
    {
        $f = F::active(); $quote = F::quote($f); $price = app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $old = InventoryReservation::sole(); $this->travelTo($old->expires_at);
        $this->rejected(['INVENTORY_EXPIRED'], fn () => app(PriceQuote::class)->read($quote->public_id, InventoryFixtures::OWNER));
        $this->rejected(['INVENTORY_EXPIRED'], fn () => app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER));
        $replacement = F::quote($f); app(PriceQuote::class)->create($replacement->public_id, InventoryFixtures::OWNER);
        $this->assertSame('expired', $old->refresh()->state); $this->assertSame($price->snapshot_hash, $price->refresh()->snapshot_hash);
        $this->assertDatabaseCount('quote_pricings', 2); $this->assertDatabaseCount('inventory_reservations', 2);
    }

    public static function drift(): array { return [['environment'], ['selection_policy'], ['inventory_policy'], ['scope_block'], ['deactivation']]; }

    #[DataProvider('drift')]
    public function test_current_read_rejects_drift_without_rewriting_retained_evidence(string $reason): void
    {
        $f = F::active(); $quote = F::quote($f); $pricing = app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $hash = $pricing->snapshot_hash;
        if ($reason === 'environment') { $this->app->instance('env', 'production'); }
        if ($reason === 'selection_policy') { config(['commerce.test_exclusive_selection_policy' => null]); }
        if ($reason === 'inventory_policy') { InventoryFixtures::configure(120); }
        if ($reason === 'scope_block') { app(ManageRightsScope::class)->block($f['scope']->id, true, 0, 'TEST-BLOCK', $f['actor']); }
        if ($reason === 'deactivation') { app(\App\Domain\Catalog\DeactivateOffer::class)->handle($f['offer'], $f['actor']); }
        $this->rejected(['SELECTION_CHANGED'], fn () => app(PriceQuote::class)->read($quote->public_id, InventoryFixtures::OWNER));
        $this->assertSame($hash, $pricing->refresh()->snapshot_hash);
        $this->assertSame(CanonicalJson::encode($pricing->snapshot), CanonicalJson::encode(app(PricingSnapshot::class)->verify($pricing, $quote)));
        $this->assertSame('held', InventoryReservation::sole()->state);
    }

    public function test_promoted_exclusive_attempts_are_atomic_idempotent_and_retained_beyond_inventory_ttl(): void
    {
        $f = F::active(); $quote = F::quote($f); $this->promotion([$f['revision']->id]);
        $service = app(ReservePricedQuote::class); $held = $service->hold($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $attempt = (string) Str::uuid(); $before = DB::table('audit_events')->count();
        try { DB::transaction(function () use ($service, $quote, $attempt) {
            $service->beginAttempt($quote->public_id, InventoryFixtures::OWNER, $attempt); throw new \RuntimeException('Order failed');
        }); } catch (\RuntimeException $error) { $this->assertSame('Order failed', $error->getMessage()); }
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertSame('held', $held['reservation']->refresh()->state); $this->assertSame('held', $held['promotion_use']->refresh()->state);
        $pending = $service->beginAttempt($quote->public_id, InventoryFixtures::OWNER, $attempt);
        $this->assertSame($attempt, $pending['reservation']->attempt_id); $this->assertSame($attempt, $pending['promotion_use']->attempt_id);
        $this->travelTo($held['reservation']->expires_at->addSecond());
        $this->assertSame($pending['reservation']->id, $service->beginAttempt($quote->public_id, InventoryFixtures::OWNER, $attempt)['reservation']->id);
        $this->rejected(['PROMOTION_ATTEMPT_CONFLICT'], fn () => $service->beginAttempt($quote->public_id, InventoryFixtures::OWNER, (string) Str::uuid()));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.inventory.pending')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
    }

    public function test_expiry_during_scope_wait_rolls_back_new_price_promotion_and_inventory(): void
    {
        $f = F::active(); $quote = F::quote($f); $this->promotion([$f['revision']->id]);
        PricingFixtures::configure(array_replace(PricingFixtures::policy(), ['effective_until' => now()->addSeconds(30)->toIso8601ZuluString()]));
        $before = DB::table('audit_events')->count(); $advanced = false;
        DB::connection()->beforeExecuting(function (string $query) use (&$advanced): void {
            // SQLite omits FOR UPDATE; waiting is simulated after pricing has been inserted.
            if (! $advanced && str_starts_with(strtolower($query), 'select') && preg_match('/from ["`]rights_scopes["`]/', $query) &&
                QuotePricing::count() === 1) {
                $advanced = true; $this->travelTo(now()->addSeconds(31));
            }
        });
        $this->rejected(['PRICING_EXPIRED'], fn () => app(PriceQuote::class)->createWithPromotion($quote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC'));
        $this->assertTrue($advanced); $this->assertSame($before, DB::table('audit_events')->count());
        foreach (['quote_pricings', 'promotion_uses', 'promotion_campaigns', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 0); }
    }
}
