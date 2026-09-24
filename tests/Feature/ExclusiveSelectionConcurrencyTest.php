<?php

namespace Tests\Feature;

use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\ReservePricedQuote;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ExclusiveSelectionFixtures as F;
use Tests\Support\InventoryFixtures;
use Tests\Support\InventoryRace;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class ExclusiveSelectionConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Exclusive selection races require independent MySQL processes.'); }
    }

    public static function races(): array
    {
        return [['same_quote'], ['exclusive_variants'], ['non_exclusive_cutoff'], ['campaign_cap'], ['same_attempt'], ['different_attempt']];
    }

    #[DataProvider('races')]
    public function test_atomic_exclusive_pricing_and_attempts_under_real_lock_contention(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $a = F::active(); $same = in_array($scenario, ['same_quote', 'same_attempt', 'different_attempt'], true);
        $b = $same ? $a : ($scenario === 'non_exclusive_cutoff' ? $a['legacy'] : F::active($scenario === 'exclusive_variants' ? $a['scope'] : null));
        $first = F::quote($a); $second = $same ? $first : F::quote($b);
        $ids = array_values(array_unique([$a['revision']->id, $b['revision']->id])); sort($ids);
        $policy = PromotionFixtures::policy(['max_uses' => $scenario === 'campaign_cap' ? 1 : 3,
            'eligibility' => ['mode' => 'offer_revisions', 'offer_revision_ids' => $ids]]);
        PromotionFixtures::configure([$policy]);
        $attempting = in_array($scenario, ['same_attempt', 'different_attempt'], true);
        if ($attempting) { app(ReservePricedQuote::class)->hold($first->public_id, InventoryFixtures::OWNER, $policy['code']); }
        $attempt = (string) Str::uuid(); $inputs = [];
        foreach ([$first, $second] as $index => $quote) {
            $inputs[] = ['quote' => $quote->public_id, 'owner' => InventoryFixtures::OWNER,
                'policy' => InventoryFixtures::policy(), 'exclusive_policy' => F::policy(), 'promotion' => $policy,
                'action' => $attempting ? 'priced_attempt' : 'priced_hold',
                'attempt' => $scenario === 'different_attempt' && $index === 1 ? (string) Str::uuid() : $attempt,
                'now' => now()->toIso8601ZuluString(), 'barrier' => 'quotes'];
        }
        $results = InventoryRace::run($this, $inputs); $outcomes = array_column($results, 'result'); sort($outcomes);
        $both = in_array($scenario, ['same_quote', 'same_attempt'], true);
        $this->assertSame($both ? ['ok', 'ok'] : ['ok', 'rejected'], $outcomes);
        if ($both) { $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']); }
        foreach ($results as $result) {
            if ($result['result'] === 'rejected') {
                $this->assertContains($result['code'], match ($scenario) {
                    'campaign_cap' => ['PROMOTION_LIMIT_REACHED'], 'different_attempt' => ['PROMOTION_ATTEMPT_CONFLICT'],
                    default => ['INVENTORY_UNAVAILABLE', 'SELECTION_CHANGED'],
                });
            }
        }
        foreach (['quote_pricings', 'promotion_campaigns', 'promotion_uses', 'inventory_reservations', 'inventory_claims'] as $table) { $this->assertDatabaseCount($table, 1); }
        $reservation = InventoryReservation::sole(); $use = PromotionUse::sole();
        $this->assertSame($attempting ? 'pending' : 'held', $reservation->state);
        $this->assertSame($reservation->state, $use->state); $this->assertSame($reservation->attempt_id, $use->attempt_id);
        foreach (['commerce.quote.priced', 'commerce.promotion.held', 'commerce.inventory.held'] as $action) {
            $this->assertSame(1, DB::table('audit_events')->where('action', $action)->count());
        }
        foreach (['commerce.promotion.pending', 'commerce.inventory.pending'] as $action) {
            $this->assertSame($attempting ? 1 : 0, DB::table('audit_events')->where('action', $action)->count());
        }
    }

    public static function activationRaces(): array { return [['duplicate'], ['successor'], ['block']]; }

    public function test_a_scope_block_committed_before_activation_acquires_its_lock_is_never_hidden_by_a_snapshot(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure();
        $a = F::prepared(); $b = F::prepared($a['scope']); $inputs = [];
        foreach ([$a, $b] as $f) {
            $inputs[] = ['action' => 'activate', 'offer' => $f['offer']->id, 'revision' => $f['revision']->id,
                'actor' => $f['actor']->id, 'scope' => $f['scope']->id, 'policy' => InventoryFixtures::policy(),
                'exclusive_policy' => F::policy(), 'now' => now()->toIso8601ZuluString(), 'barrier' => 'rights_scopes'];
        }
        $results = InventoryRace::run($this, $inputs, function () use ($a): void {
            // Both independent workers have reached their scope lock, after earlier
            // track/offer/license locks. Commit the block BEFORE releasing that barrier.
            app(\App\Domain\Commerce\Inventory\ManageRightsScope::class)->block($a['scope']->id, true, 0, 'COMMITTED-BEFORE-LOCK', $a['actor']);
            $this->assertSame(0, DB::transactionLevel());
        });
        $this->assertSame(['rejected', 'rejected'], array_column($results, 'result'));
        $this->assertSame(['SELECTION_CHANGED', 'SELECTION_CHANGED'], array_column($results, 'code'));
        $this->assertFalse($a['offer']->refresh()->is_active); $this->assertFalse($b['offer']->refresh()->is_active);
        $this->assertDatabaseCount('exclusive_activations', 0);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'catalog.offer.exclusive_activated')->count());
    }

    #[DataProvider('activationRaces')]
    public function test_activation_cannot_lose_sibling_or_administrative_controls(string $scenario): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); $f = F::prepared();
        $first = ['action' => 'activate', 'offer' => $f['offer']->id, 'revision' => $f['revision']->id,
            'actor' => $f['actor']->id, 'scope' => $f['scope']->id, 'policy' => InventoryFixtures::policy(),
            'exclusive_policy' => F::policy(), 'now' => now()->toIso8601ZuluString(), 'barrier' => 'tracks'];
        $second = $first;
        if ($scenario === 'successor') { $second['action'] = 'publish_successor'; $second['offer'] = $f['legacy']['offer']->id; }
        if ($scenario === 'block') { $second['action'] = 'block'; $second['barrier'] = 'rights_scopes'; }
        $results = InventoryRace::run($this, [$first, $second]);
        $this->assertSame('ok', $results[1]['result']);
        if ($scenario === 'duplicate') {
            $this->assertSame(['ok', 'ok'], array_column($results, 'result'));
            $this->assertSame($results[0]['effect_id'], $results[1]['effect_id']);
            $this->assertDatabaseCount('exclusive_activations', 1);
            $this->assertSame(1, DB::table('audit_events')->where('action', 'catalog.offer.exclusive_activated')->count());
        } elseif ($scenario === 'successor') {
            $this->assertContains($results[0]['result'], ['ok', 'rejected']);
            if ($results[0]['result'] === 'rejected') {
                $this->assertSame('INVENTORY_SCOPE_UNAVAILABLE', $results[0]['code']);
                $this->assertFalse($f['offer']->refresh()->is_active);
            } else {
                $items = $f['legacy']['items']; $items[0]['offerRevisionId'] = $f['legacy']['offer']->refresh()->current_revision_id;
                try { F::quote(['items' => $items]); $this->fail('Unlinked successor escaped cutoff.'); }
                catch (QuoteException $error) { $this->assertSame('INVENTORY_UNAVAILABLE', $error->errorCode); }
            }
        } else {
            $this->assertContains($results[0]['result'], ['ok', 'rejected']);
            $this->assertTrue($f['scope']->refresh()->blocked);
            try { F::quote($f); $this->fail('Blocked scope became selectable.'); }
            catch (QuoteException $error) { $this->assertSame('SELECTION_CHANGED', $error->errorCode); }
        }
        $this->assertDatabaseCount('inventory_reservations', 0); $this->assertDatabaseCount('quote_pricings', 0);
    }
}
