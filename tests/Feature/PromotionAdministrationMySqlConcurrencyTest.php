<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\PromotionAvailability;
use App\Domain\Commerce\Models\PromotionAvailabilityRevision;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\QuotePricing;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionAdministration;
use App\Domain\Commerce\PromotionUsage;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\LicenseFixtures;
use Tests\Support\PromotionAdministrationRace;
use Tests\Support\PromotionFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class PromotionAdministrationMySqlConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Independent promotion administration locking is verified on MySQL, not SQLite.');
        }
    }

    public static function commerceOrderings(): array
    {
        return [
            'disable before hold' => ['price', 0],
            'hold before disable' => ['price', 1],
            'disable before pending attempt' => ['attempt', 0],
            'pending attempt before disable' => ['attempt', 1],
        ];
    }

    #[DataProvider('commerceOrderings')]
    public function test_disabling_and_new_commerce_serialize_on_the_same_campaign_without_partial_effects(string $operation, int $first): void
    {
        $this->prepareEnvironment();
        $actor = LicenseFixtures::admin();
        $administration = app(PromotionAdministration::class);
        $policy = PromotionFixtures::policy();
        $campaign = $administration->create($policy, $actor);
        $administration->setAvailability($campaign->id, 1, true, $actor);
        $retainedCampaign = $campaign->refresh()->getRawOriginal();
        $owner = bin2hex(random_bytes(32));
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), QuoteFixtures::selection()['items']);
        $prior = $operation === 'attempt' ? app(PriceQuote::class)->createWithPromotion($quote->public_id, $owner, $policy['code']) : null;
        $attempt = (string) Str::uuid();
        $jobs = [
            ['operation' => 'availability', 'campaign_id' => $campaign->id, 'revision' => 2, 'enabled' => false, 'actor_id' => $actor->id],
            ['operation' => $operation, 'quote' => $quote->public_id, 'owner' => $owner, 'code' => $policy['code'], 'attempt' => $attempt],
        ];
        $results = PromotionAdministrationRace::run($this, $campaign->id, $jobs, $first);
        $this->assertSame('ok', $results[0]['result']);
        $this->assertSame(3, $results[0]['revision']);
        $this->assertFalse($results[0]['enabled']);
        $this->assertSame($first === 1 ? 'ok' : 'rejected', $results[1]['result']);
        if ($first === 0) {
            $this->assertSame('PROMOTION_UNAVAILABLE', $results[1]['code']);
            $this->assertSame(409, $results[1]['status']);
        }

        $availability = PromotionAvailability::sole();
        $this->assertFalse($availability->enabled);
        $this->assertSame(3, $availability->revision);
        $this->assertSame(3, PromotionAvailabilityRevision::count());
        $this->assertSame($retainedCampaign, $campaign->refresh()->getRawOriginal(), 'Administrative availability must never edit retained campaign terms.');
        $this->assertDatabaseCount('promotion_campaigns', 1);
        $uses = $operation === 'attempt' || $first === 1 ? 1 : 0;
        $pending = $operation === 'attempt' && $first === 1 ? 1 : 0;
        $this->assertDatabaseCount('promotion_uses', $uses);
        $this->assertDatabaseCount('quote_pricings', $uses);
        $this->assertSame($pending, PromotionUse::where('state', 'pending')->count());
        $this->assertSame($uses - $pending, PromotionUse::where('state', 'held')->count());
        $this->assertSame($uses, AuditEvent::where('action', 'commerce.promotion.held')->count());
        $this->assertSame($uses, AuditEvent::where('action', 'commerce.quote.priced')->count());
        $this->assertSame($pending, AuditEvent::where('action', 'commerce.promotion.pending')->count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.created')->count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.enabled')->count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.disabled')->count());
        if ($uses === 1) {
            $pricing = QuotePricing::sole();
            $use = PromotionUse::sole();
            $this->assertSame($campaign->id, $use->promotion_campaign_id);
            $this->assertSame($pricing->id, $use->quote_pricing_id);
            $this->assertEquals($policy, $pricing->snapshot['promotion']);
            $this->assertSame($pending === 1 ? $attempt : null, $use->attempt_id);
            $this->assertSame($pending === 1, $use->pending_at !== null);
            if ($prior !== null) {
                $this->assertSame($prior->id, $pricing->id);
                $this->assertSame($prior->snapshot_hash, $pricing->snapshot_hash);
            }
            if ($first === 1) {
                $this->assertSame($operation === 'attempt' ? $use->id : $pricing->id, $results[1]['effect_id']);
                // Historical integrity readers still verify the retained result after
                // disable. This is deliberately not a request for new current pricing.
                $verified = DB::transaction(fn () => app(PromotionUsage::class)->currentUse($pricing));
                $this->assertSame($use->id, $verified->id);
                $this->assertSame($use->state, $verified->state);
            }
        }
    }

    public function test_same_revision_confirmations_have_one_winner_and_one_audit_effect(): void
    {
        $this->prepareEnvironment();
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $administration = app(PromotionAdministration::class);
        $campaign = $administration->create(PromotionFixtures::policy(), $actors[0]);
        $administration->setAvailability($campaign->id, 1, true, $actors[0]);
        $jobs = array_map(fn ($actor) => [
            'operation' => 'availability', 'campaign_id' => $campaign->id, 'revision' => 2,
            'enabled' => false, 'actor_id' => $actor->id,
        ], $actors);
        $results = PromotionAdministrationRace::run($this, $campaign->id, $jobs, 0);
        $this->assertSame('ok', $results[0]['result']);
        $this->assertSame('rejected', $results[1]['result']);
        $this->assertArrayHasKey('promotion', $results[1]['errors']);
        $this->assertSame(3, PromotionAvailability::sole()->revision);
        $this->assertFalse(PromotionAvailability::sole()->enabled);
        $this->assertSame(3, PromotionAvailabilityRevision::count());
        $this->assertSame($actors[0]->id, AuditEvent::where('action', 'commerce.promotion.disabled')->sole()->actor_id);
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.enabled')->count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.created')->count());
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('quote_pricings', 0);
    }

    public static function staleOrderings(): array
    {
        return ['fresh disable first' => [0], 'stale enable first' => [1]];
    }

    #[DataProvider('staleOrderings')]
    public function test_returning_to_enabled_never_revives_a_stale_opposing_confirmation(int $first): void
    {
        $this->prepareEnvironment();
        $actors = [LicenseFixtures::admin(), LicenseFixtures::admin()];
        $administration = app(PromotionAdministration::class);
        $campaign = $administration->create(PromotionFixtures::policy(), $actors[0]);
        $administration->setAvailability($campaign->id, 1, true, $actors[0]);
        $administration->setAvailability($campaign->id, 2, false, $actors[0]);
        $administration->setAvailability($campaign->id, 3, true, $actors[0]);
        $jobs = [
            ['operation' => 'availability', 'campaign_id' => $campaign->id, 'revision' => 4, 'enabled' => false, 'actor_id' => $actors[0]->id],
            ['operation' => 'availability', 'campaign_id' => $campaign->id, 'revision' => 2, 'enabled' => true, 'actor_id' => $actors[1]->id],
        ];
        $results = PromotionAdministrationRace::run($this, $campaign->id, $jobs, $first);
        $this->assertSame('ok', $results[0]['result']);
        $this->assertSame('rejected', $results[1]['result']);
        $this->assertArrayHasKey('promotion', $results[1]['errors']);
        $this->assertSame(5, PromotionAvailability::sole()->revision);
        $this->assertFalse(PromotionAvailability::sole()->enabled);
        $this->assertSame(5, PromotionAvailabilityRevision::count());
        $this->assertSame(2, AuditEvent::where('action', 'commerce.promotion.disabled')->count());
        $this->assertSame(2, AuditEvent::where('action', 'commerce.promotion.enabled')->count());
        $this->assertSame(1, AuditEvent::where('action', 'commerce.promotion.created')->count());
        $this->assertSame(0, AuditEvent::where('actor_id', $actors[1]->id)->count());
        $this->assertDatabaseCount('promotion_campaigns', 1);
        $this->assertDatabaseCount('promotion_uses', 0);
        $this->assertDatabaseCount('quote_pricings', 0);
    }

    private function prepareEnvironment(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        config(['commerce.test_pricing_policy' => null, 'commerce.test_promotions' => null]);
    }
}
