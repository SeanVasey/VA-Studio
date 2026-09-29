<?php

namespace Tests\Feature;

use App\Domain\Commerce\ComparePricingSettlement;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PricingSnapshot;
use App\Domain\Commerce\PricingSnapshotV1;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\Support\PricingFixtures;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionPricingTest extends TestCase
{
    use RefreshDatabase;
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        PricingFixtures::configure(null);
        PromotionFixtures::configure([PromotionFixtures::policy()]);
    }

    private function quote(?array $items = null): Quote
    {
        return app(CreateQuote::class)->handle(self::OWNER, (string) Str::uuid(), $items ?? QuoteFixtures::selection()['items']);
    }

    private function price(Quote $quote): QuotePricing
    {
        return app(PriceQuote::class)->createWithPromotion($quote->public_id, self::OWNER, 'SYNTHETIC');
    }

    private function rejects(string $code, callable $operation): void
    {
        try { $operation(); $this->fail('Expected rejection: '.$code); }
        catch (QuoteException $e) { $this->assertSame($code, $e->errorCode); }
    }

    public function test_promotion_freezes_discount_trace_and_unknown_tax_without_mutating_the_quote(): void
    {
        $quote = $this->quote();
        $oldHash = $quote->snapshot_hash;
        $pricing = $this->price($quote);
        $snapshot = $pricing->snapshot;
        $this->assertSame(2, $snapshot['schema_version']);
        $this->assertSame('vasey-quote-pricing-v2', $snapshot['algorithm']);
        $this->assertSame(500, $snapshot['discount_minor']);
        $this->assertSame(4499, $snapshot['tax_basis_minor']);
        $this->assertNull($snapshot['tax_minor']);
        $this->assertNull($snapshot['total_minor']);
        $this->assertSame($pricing->snapshot_hash, CanonicalJson::hash(app(PricingSnapshot::class)->verify($pricing, $quote)));
        $this->assertSame($oldHash, $quote->refresh()->snapshot_hash);
        $this->assertSame('held', PromotionUse::sole()->state);
        $this->assertSame($pricing->id, PromotionUse::sole()->quote_pricing_id);
        $this->assertSame($snapshot['promotion_hash'], PromotionCampaign::sole()->snapshot_hash);
        $data = app(PricingSnapshot::class)->present($pricing);
        $hash = $data['pricingHash']; unset($data['pricingHash']);
        $this->assertSame(CanonicalJson::hash($data), $hash);
        $this->assertSame(2, $data['pricingSchema']);
        $this->assertTrue($data['testOnly']);
        $this->assertFalse($data['payable']);
    }

    public function test_discount_allocation_precedes_per_line_tax_rounding(): void
    {
        $policy = PricingFixtures::policy(); $policy['tax']['rate_bps'] = 5000;
        PricingFixtures::configure($policy);
        PromotionFixtures::configure([PromotionFixtures::policy(['discount' => ['type' => 'fixed', 'amount_minor' => 1]])]);
        $items = [...QuoteFixtures::selection(1)['items'], ...QuoteFixtures::selection(1)['items']];
        $pricing = $this->price($this->quote($items));
        $this->assertSame([1, 0], array_column($pricing->snapshot['lines'], 'discount_minor'));
        $this->assertSame([0, 1], array_column($pricing->snapshot['lines'], 'tax_minor'));
        $this->assertSame(2, $pricing->snapshot['total_minor']);
        $this->assertSame(1, $pricing->snapshot['tax_basis_minor']);
        $this->assertSame(1, $pricing->snapshot['tax_minor']);
    }

    public function test_v1_evidence_and_api_projection_remain_unchanged(): void
    {
        $oldQuote = $this->quote();
        $old = app(PriceQuote::class)->create($oldQuote->public_id, self::OWNER);
        $oldSnapshot = $old->snapshot;
        $oldData = app(PricingSnapshotV1::class)->present($old);
        $this->price($this->quote($oldQuote->request));
        $this->assertSame(CanonicalJson::encode($oldSnapshot), CanonicalJson::encode(app(PricingSnapshot::class)->verify($old->refresh(), $oldQuote)));
        $this->assertSame($oldData, app(PricingSnapshot::class)->present(app(PriceQuote::class)->read($oldQuote->public_id, self::OWNER)));
        $this->assertSame(1, $oldData['pricingSchema']);
        $this->rejects('PRICING_CHANGED', fn () => $this->price($oldQuote));
        $this->assertDatabaseCount('quote_pricings', 2);
        $this->assertDatabaseCount('promotion_uses', 1);
    }

    public function test_retries_use_one_slot_and_changing_or_removing_the_code_requires_a_new_quote(): void
    {
        $quote = $this->quote(); $pricing = $this->price($quote);
        $this->assertSame($pricing->public_id, $this->price($quote)->public_id);
        $this->assertSame($pricing->snapshot_hash, app(PriceQuote::class)->read($quote->public_id, self::OWNER)->snapshot_hash);
        $this->rejects('PRICING_CHANGED', fn () => app(PriceQuote::class)->create($quote->public_id, self::OWNER));
        $this->rejects('PRICING_CHANGED', fn () => app(PriceQuote::class)->createWithPromotion($quote->public_id, self::OWNER, 'OTHER'));
        $this->assertDatabaseCount('promotion_uses', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.promotion.held')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.priced')->count());
    }

    public function test_campaign_identity_cannot_reset_capacity_or_reuse_a_code_with_changed_rules(): void
    {
        $quote = $this->quote(); $pricing = $this->price($quote);
        foreach ([['version' => 2], ['max_uses' => 999], ['key' => 'different-campaign']] as $change) {
            PromotionFixtures::configure([PromotionFixtures::policy($change)]);
            $this->rejects('PRICING_CHANGED', fn () => $this->price($quote));
            $this->rejects('PROMOTION_CHANGED', fn () => $this->price($this->quote($quote->request)));
        }
        PromotionFixtures::configure([]);
        $this->rejects('PROMOTION_UNAVAILABLE', fn () => app(PriceQuote::class)->read($quote->public_id, self::OWNER));
        $this->assertSame($pricing->snapshot_hash, CanonicalJson::hash(app(PricingSnapshot::class)->verify($pricing, $quote)));
        $this->assertDatabaseCount('promotion_campaigns', 1);
        $this->assertDatabaseCount('promotion_uses', 1);
        $this->assertDatabaseCount('quote_pricings', 1);
    }

    public function test_full_capacity_rolls_back_pricing_and_an_expired_unstarted_hold_releases_capacity(): void
    {
        PromotionFixtures::configure([PromotionFixtures::policy(['max_uses' => 1])]);
        $quote = $this->quote(); $pricing = $this->price($quote);
        $this->rejects('PROMOTION_LIMIT_REACHED', fn () => $this->price($this->quote($quote->request)));
        $this->assertDatabaseCount('quote_pricings', 1);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.quote.priced')->count());
        $this->travelTo($pricing->expires_at);
        $this->rejects('QUOTE_EXPIRED', fn () => $this->price($quote));
        $next = $this->price($this->quote($quote->request));
        $this->assertNotSame($pricing->public_id, $next->public_id);
        $this->assertDatabaseCount('promotion_uses', 2); // Expiry is derived; original hold evidence is retained.
        $this->assertSame($pricing->snapshot_hash, $pricing->refresh()->snapshot_hash);
    }

    public function test_pending_attempts_are_idempotent_and_never_release_capacity_at_quote_expiry(): void
    {
        PromotionFixtures::configure([PromotionFixtures::policy(['max_uses' => 1])]);
        $quote = $this->quote(); $pricing = $this->price($quote);
        $attempt = (string) Str::uuid();
        $usage = app(PromotionUsage::class);
        $use = $usage->beginAttempt($quote->public_id, self::OWNER, $attempt);
        $this->assertSame('pending', $use->state);
        $this->assertSame($use->id, $usage->beginAttempt($quote->public_id, self::OWNER, $attempt)->id);
        $this->rejects('PROMOTION_ATTEMPT_CONFLICT', fn () => $usage->beginAttempt($quote->public_id, self::OWNER, (string) Str::uuid()));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
        $this->travelTo($pricing->expires_at->addHour());
        $this->rejects('PROMOTION_LIMIT_REACHED', fn () => $this->price($this->quote($quote->request)));
        $this->assertSame('pending', $use->refresh()->state);
    }

    public function test_clock_rollback_cannot_reuse_capacity_already_assigned_to_a_newer_hold_or_attempt(): void
    {
        $this->travelTo(now()->startOfSecond());
        PromotionFixtures::configure([PromotionFixtures::policy(['max_uses' => 1])]);
        $oldQuote = $this->quote(); $oldPricing = $this->price($oldQuote);
        $this->travelTo($oldPricing->expires_at);
        $newQuote = $this->quote(); $newPricing = $this->price($newQuote);
        $usage = app(PromotionUsage::class);
        $this->travelTo($oldPricing->expires_at->subSecond());
        $this->rejects('PROMOTION_LIMIT_REACHED', fn () => $usage->beginAttempt($oldQuote->public_id, self::OWNER, (string) Str::uuid()));
        $this->assertSame(0, PromotionUse::where('state', 'pending')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());

        $this->travelTo($oldPricing->expires_at);
        $attempt = (string) Str::uuid();
        $use = $usage->beginAttempt($newQuote->public_id, self::OWNER, $attempt);
        $this->assertSame($newPricing->id, $use->quote_pricing_id);
        $this->assertSame($use->id, $usage->beginAttempt($newQuote->public_id, self::OWNER, $attempt)->id);
        $this->travelTo($oldPricing->expires_at->subSecond());
        $this->rejects('PROMOTION_LIMIT_REACHED', fn () => $usage->beginAttempt($oldQuote->public_id, self::OWNER, (string) Str::uuid()));
        $this->assertSame(1, PromotionUse::where('state', 'pending')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
        $this->assertSame('held', PromotionUse::where('quote_pricing_id', $oldPricing->id)->sole()->state);
        $this->assertSame($oldPricing->snapshot_hash, $oldPricing->refresh()->snapshot_hash);
    }

    public function test_attempt_identity_is_unique_and_outer_rollback_removes_the_transition_and_audit(): void
    {
        $first = $this->quote(); $this->price($first);
        $second = $this->quote($first->request); $this->price($second);
        $attempt = (string) Str::uuid();
        $usage = app(PromotionUsage::class);
        $usage->beginAttempt($first->public_id, self::OWNER, $attempt);
        $this->rejects('PROMOTION_ATTEMPT_CONFLICT', fn () => $usage->beginAttempt($second->public_id, self::OWNER, $attempt));
        try {
            DB::transaction(function () use ($usage, $second) {
                $usage->beginAttempt($second->public_id, self::OWNER, (string) Str::uuid());
                throw new RuntimeException('Synthetic downstream failure');
            });
        } catch (RuntimeException) { $this->assertTrue(true); }
        $this->assertSame(1, PromotionUse::where('state', 'pending')->count());
        $this->assertSame(1, PromotionUse::where('state', 'held')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.promotion.pending')->count());
    }

    public function test_ownership_and_current_publication_are_required_before_policy_or_attempt_access(): void
    {
        $fixture = QuoteFixtures::selection(); $quote = $this->quote($fixture['items']);
        $this->price($quote);
        config(['commerce.test_promotions' => 'private invalid configuration']);
        $this->rejects('QUOTE_NOT_FOUND', fn () => app(PriceQuote::class)->createWithPromotion($quote->public_id, str_repeat('b', 64), 'SYNTHETIC'));
        $this->rejects('QUOTE_NOT_FOUND', fn () => app(PromotionUsage::class)->beginAttempt($quote->public_id, str_repeat('b', 64), (string) Str::uuid()));
        PromotionFixtures::configure([PromotionFixtures::policy()]);
        app(\App\Domain\Catalog\DeactivateOffer::class)->handle($fixture['offer'], $fixture['actor']);
        $this->rejects('SELECTION_CHANGED', fn () => $this->price($quote));
        $this->rejects('SELECTION_CHANGED', fn () => app(PromotionUsage::class)->beginAttempt($quote->public_id, self::OWNER, (string) Str::uuid()));
        $this->assertSame(0, PromotionUse::where('state', 'pending')->count());
    }

    public function test_policy_end_and_lock_waits_cannot_extend_a_hold_or_create_a_late_attempt(): void
    {
        $this->travelTo(now()->startOfSecond());
        $policy = PromotionFixtures::policy(['effective_until' => now()->addSeconds(20)->toIso8601ZuluString()]);
        PromotionFixtures::configure([$policy]);
        $quote = $this->quote(); $pricing = $this->price($quote);
        $this->assertSame($policy['effective_until'], $pricing->expires_at->toIso8601ZuluString());
        $waited = false;
        DB::listen(function ($query) use (&$waited, $pricing) {
            if (! $waited && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'promotion_campaigns') && str_contains($query->sql, 'policy_key')) {
                $waited = true; $this->travelTo($pricing->expires_at);
            }
        });
        $this->rejects('PRICING_EXPIRED', fn () => app(PromotionUsage::class)->beginAttempt($quote->public_id, self::OWNER, (string) Str::uuid()));
        $this->assertTrue($waited);
        $this->assertSame('held', PromotionUse::sole()->state);
    }

    public function test_first_hold_expiry_during_campaign_lock_rolls_back_every_new_effect(): void
    {
        $quote = $this->quote();
        $waited = false;
        DB::listen(function ($query) use (&$waited, $quote) {
            if (! $waited && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'promotion_campaigns') && str_contains($query->sql, 'policy_key')) {
                $waited = true; $this->travelTo($quote->expires_at);
            }
        });
        $this->rejects('PRICING_EXPIRED', fn () => $this->price($quote));
        $this->assertTrue($waited);
        $this->assertDatabaseCount('promotion_campaigns', 0);
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('quote_pricings', 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'commerce.promotion.held')->count());
    }

    public function test_model_and_database_guards_preserve_campaigns_hold_identity_and_pending_state(): void
    {
        $quote = $this->quote(); $this->price($quote);
        $campaign = PromotionCampaign::sole(); $use = PromotionUse::sole();
        foreach ([fn () => $campaign->update(['code' => 'OTHER']), fn () => $campaign->delete(), fn () => $use->update(['state' => 'pending']), fn () => $use->delete()] as $op) {
            try { $op(); $this->fail('Model changed evidence.'); } catch (LogicException) { $this->assertTrue(true); }
        }
        foreach ([fn () => DB::table('promotion_campaigns')->where('id', $campaign->id)->update(['code' => 'OTHER']),
            fn () => DB::table('promotion_campaigns')->where('id', $campaign->id)->delete(),
            fn () => DB::table('promotion_uses')->where('id', $use->id)->delete(),
            fn () => DB::table('promotion_uses')->where('id', $use->id)->update(['expires_at' => now()->addDay()]),
            fn () => DB::table('promotion_uses')->where('id', $use->id)->update(['state' => 'pending']),
            fn () => DB::table('promotion_uses')->where('id', $use->id)->update(['state' => 'pending', 'attempt_id' => (string) Str::uuid(), 'pending_at' => $use->expires_at]),
        ] as $op) {
            try { DB::transaction($op); $this->fail('SQL changed evidence.'); } catch (QueryException) { $this->assertTrue(true); }
        }
        $row = $use->refresh()->getAttributes(); unset($row['id']);
        try { DB::transaction(fn () => DB::table('promotion_uses')->insert($row)); $this->fail('Duplicate hold inserted.'); }
        catch (QueryException) { $this->assertDatabaseCount('promotion_uses', 1); }
        app(PromotionUsage::class)->beginAttempt($quote->public_id, self::OWNER, (string) Str::uuid());
        try { DB::transaction(fn () => DB::table('promotion_uses')->where('id', $use->id)->update(['state' => 'held', 'attempt_id' => null, 'pending_at' => null])); $this->fail('Pending use was released.'); }
        catch (QueryException) { $this->assertSame('pending', $use->refresh()->state); }
    }

    public function test_comparison_checks_discounted_amounts_and_provider_tax_caps_on_net_basis(): void
    {
        PricingFixtures::configure(PricingFixtures::policy());
        $quote = $this->quote(); $pricing = $this->price($quote);
        $comparator = app(ComparePricingSettlement::class);
        $observation = PromotionFixtures::observation($pricing);
        $this->assertTrue($comparator->handle($pricing, $observation)['amounts_match']);
        $this->assertSame(4836, $observation['total_minor']);
        $altered = $observation; $altered['discount_minor']--;
        $this->assertFalse($comparator->handle($pricing, $altered)['amounts_match']);
        $altered = $observation; $altered['lines'][0]['tax_basis_minor']++;
        $this->assertFalse($comparator->handle($pricing, $altered)['amounts_match']);
        PricingFixtures::configure(PricingFixtures::policy('provider_calculated'));
        $pending = $this->price($this->quote($quote->request));
        $value = PromotionFixtures::observation($pending, 450);
        $this->assertTrue($comparator->handle($pending, $value, PricingFixtures::taxResult($value))['amounts_match']);
        $value = PromotionFixtures::observation($pending, 451);
        $this->assertSame('tax_limit_exceeded', $comparator->handle($pending, $value, PricingFixtures::taxResult($value))['reason']);
        $this->app->instance('env', 'production');
        $this->assertFalse($comparator->handle($pending, $value, PricingFixtures::taxResult($value))['amounts_match']);
    }

    public function test_rehashed_discount_trace_tampering_cannot_pass_historical_reproduction(): void
    {
        $quote = $this->quote(); $pricing = $this->price($quote);
        $snapshot = $pricing->snapshot; $snapshot['discount_trace']['allocations'][0]['extra_minor']++;
        $pricing->snapshot = $snapshot; $pricing->snapshot_hash = CanonicalJson::hash($snapshot);
        $this->expectException(InvalidArgumentException::class);
        app(PricingSnapshot::class)->verify($pricing, $quote);
    }
}
