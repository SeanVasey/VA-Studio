<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\PromotionCampaign;
use App\Support\CanonicalJson;
use App\Domain\Commerce\ReservePricedQuote;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InventoryFixtures as F;
use Tests\Support\InventoryRace;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class ReservePricedQuoteConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Atomic priced inventory races require independent MySQL processes.'); }
    }

    public static function races(): array
    {
        return [['same_quote'], ['shared_scope'], ['campaign_cap'], ['same_attempt'], ['different_attempt']];
    }

    #[DataProvider('races')]
    public function test_both_resources_commit_or_rollback_under_shared_lock_contention(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $a = F::selection(); $same = in_array($scenario, ['same_quote', 'same_attempt', 'different_attempt'], true);
        $b = $same ? $a : F::selection($scenario === 'shared_scope' ? $a['scope'] : null);
        $policy = PromotionFixtures::policy(['max_uses' => 1]); PromotionFixtures::configure([$policy]);
        $attempting = in_array($scenario, ['same_attempt', 'different_attempt'], true);
        if ($attempting) { app(ReservePricedQuote::class)->hold($a['quote']->public_id, F::OWNER, $policy['code']); }
        $attempt = (string) Str::uuid(); $inputs = [];
        foreach ([$a, $b] as $index => $f) {
            $promotion = $policy;
            if ($scenario === 'shared_scope' && $index === 1) {
                $promotion = array_replace($policy, ['key' => 'other-synthetic', 'code' => 'OTHER_SYNTHETIC']);
            }
            if ($scenario === 'shared_scope') {
                // Register identities before the barrier so unrelated first-insert gap locks cannot block arrival.
                PromotionCampaign::create(['policy_key' => $promotion['key'], 'code' => $promotion['code'],
                    'snapshot' => $promotion, 'snapshot_hash' => CanonicalJson::hash($promotion), 'created_at' => now()]);
            }
            $inputs[] = ['quote' => $f['quote']->public_id, 'owner' => F::OWNER, 'policy' => F::policy(),
                'promotion' => $promotion, 'action' => $attempting ? 'priced_attempt' : 'priced_hold',
                'attempt' => $scenario === 'different_attempt' && $index === 1 ? (string) Str::uuid() : $attempt,
                'now' => now()->toIso8601ZuluString(),
                'barrier' => $same ? 'quotes' : ($scenario === 'shared_scope' ? 'rights_scopes' : 'promotion_campaigns')];
        }
        $results = InventoryRace::run($this, $inputs);
        $outcomes = array_column($results, 'result'); sort($outcomes);
        $both = in_array($scenario, ['same_quote', 'same_attempt'], true);
        $this->assertSame($both ? ['ok', 'ok'] : ['ok', 'rejected'], $outcomes);
        if ($both) { $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']); }
        foreach ($results as $result) {
            if ($result['result'] === 'rejected') {
                $this->assertSame(match ($scenario) {
                    'shared_scope' => 'INVENTORY_UNAVAILABLE', 'campaign_cap' => 'PROMOTION_LIMIT_REACHED',
                    default => 'PROMOTION_ATTEMPT_CONFLICT',
                }, $result['code']);
            }
        }
        foreach (['quote_pricings', 'promotion_uses', 'inventory_reservations', 'inventory_claims'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->assertDatabaseCount('promotion_campaigns', $scenario === 'shared_scope' ? 2 : 1);
        $reservation = InventoryReservation::sole(); $use = PromotionUse::sole();
        $this->assertSame($attempting ? 'pending' : 'held', $reservation->state);
        $this->assertSame($reservation->state, $use->state);
        $this->assertSame($reservation->attempt_id, $use->attempt_id);
        foreach (['commerce.quote.priced', 'commerce.inventory.held', 'commerce.promotion.held'] as $action) {
            $this->assertSame(1, DB::table('audit_events')->where('action', $action)->count());
        }
        foreach (['commerce.inventory.pending', 'commerce.promotion.pending'] as $action) {
            $this->assertSame($attempting ? 1 : 0, DB::table('audit_events')->where('action', $action)->count());
        }
    }
}
