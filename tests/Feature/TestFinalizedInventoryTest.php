<?php

namespace Tests\Feature;

use App\Domain\Catalog\ActivateExclusiveOffer;
use App\Domain\Catalog\DeactivateOffer;
use App\Domain\Catalog\ExclusiveOfferScope;
use App\Domain\Catalog\PrepareExclusiveOffer;
use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizationPolicy;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ReserveQuoteInventory;
use App\Domain\Commerce\Inventory\SelectionInventory;
use App\Domain\Commerce\Models\InventoryReservation;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionUsage;
use App\Domain\Commerce\QuoteException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\PaymentFixtures as F;
use Tests\TestCase;

/** Inventory effects through genuine synthetic verification/finalization, with all SQL guards enabled. */
class TestFinalizedInventoryTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        config(['payments.stripe.finalization_enabled' => true,
            'payments.stripe.finalization_policy' => json_encode(FinalizationPolicy::CONTRACT, JSON_THROW_ON_ERROR)]);
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    private function finalizedFixture(bool $exclusive = false, bool $promoted = false): array
    {
        $fixture = F::started($this->gateway, $exclusive, $promoted);
        $this->assertSame('awaiting_finalization', app(VerifyTestPayment::class)->reconcile($fixture['intent']));
        $payment = VerifiedPayment::where('order_id', $fixture['order']->id)->sole();
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($payment->id));

        return $fixture;
    }

    private function inventoryRejection(callable $operation, array $codes): void
    {
        try { $operation(); $this->fail('Finalized inventory was reused.'); }
        catch (QuoteException $error) { $this->assertContains($error->errorCode, $codes); }
    }

    public function test_exclusive_sale_blocks_own_quote_linked_siblings_and_unlinked_successors(): void
    {
        $f = $this->finalizedFixture(true); $availability = app(SelectionInventory::class);
        $this->assertFalse($availability->available($f['revision']->id));
        $this->assertFalse($availability->available($f['revision']->id, $f['quote']->id));
        $this->assertFalse($availability->available($f['legacy']['revision']->id));
        $this->assertSame([$f['scope']->id], $availability->governedScopes($f['track']->id));
        $this->inventoryRejection(fn () => app(ReserveQuoteInventory::class)->read($f['quote']->public_id, InventoryFixtures::OWNER),
            ['SELECTION_CHANGED', 'INVENTORY_UNAVAILABLE']);

        $successor = app(PublishOffer::class)->handle(app(SaveOfferDraft::class)->handle($f['legacy']['offer'],
            ['price_minor' => 5555], $f['actor']), $f['actor']);
        $this->assertDatabaseMissing('rights_scope_offers', ['offer_revision_id' => $successor->id]);
        $this->assertFalse($availability->available($successor->id));
        $items = $f['legacy']['items']; $items[0]['offerRevisionId'] = $successor->id;
        $this->inventoryRejection(fn () => app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $items),
            ['SELECTION_CHANGED', 'INVENTORY_UNAVAILABLE']);

        $other = InventoryFixtures::selection();
        $this->assertTrue($availability->available($other['revision']->id));
        $this->assertDatabaseCount('exclusive_sales', 1);
        $this->assertDatabaseCount('license_grants', 1);
    }

    public function test_sold_scope_rejects_reactivation_and_new_exclusive_preparation(): void
    {
        $f = $this->finalizedFixture(true);
        $this->assertContains('The underlying rights scope has already been sold exclusively.',
            app(ExclusiveOfferScope::class)->blockers($f['revision']));
        app(DeactivateOffer::class)->handle($f['offer'], $f['actor']);
        $this->inventoryRejection(fn () => app(ActivateExclusiveOffer::class)->handle($f['offer']->refresh(), $f['revision']->id, $f['actor']),
            ['SELECTION_CHANGED']);
        try {
            app(PrepareExclusiveOffer::class)->handle($f['offer']->refresh(), $f['scope']->id, 'SYNTHETIC-SOLD-RETRY', $f['actor']);
            $this->fail('Sold scope accepted a new exclusive revision.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('inventory', $error->errors());
        }
        $this->assertFalse($f['offer']->refresh()->is_active);
        $this->assertSame($f['revision']->id, $f['offer']->current_revision_id);
        $this->assertDatabaseCount('exclusive_sales', 1);
        $this->assertDatabaseCount('exclusive_activations', 1);
    }

    public function test_non_exclusive_consumption_releases_capacity_without_reviving_old_reservation(): void
    {
        $f = $this->finalizedFixture(); $attempt = $f['order']->attempt()->sole();
        $reservation = InventoryReservation::findOrFail($attempt->inventory_reservation_id);
        $this->assertSame('consumed', $reservation->state); $consumedAt = $reservation->consumed_at;
        $this->assertNotNull($consumedAt);
        $service = app(ReserveQuoteInventory::class);
        foreach (['hold', 'read'] as $operation) {
            $this->inventoryRejection(fn () => $service->{$operation}($f['quote']->public_id, InventoryFixtures::OWNER), ['INVENTORY_UNAVAILABLE']);
        }
        foreach ([$attempt->public_id, (string) Str::uuid()] as $attemptId) {
            $this->inventoryRejection(fn () => $service->beginAttempt($f['quote']->public_id, InventoryFixtures::OWNER, $attemptId),
                ['INVENTORY_UNAVAILABLE']);
        }
        $nextQuote = app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        $next = $service->hold($nextQuote->public_id, InventoryFixtures::OWNER);
        $this->assertSame('held', $next->state); $this->assertNotSame($reservation->id, $next->id);
        $this->assertSame('consumed', $reservation->refresh()->state);
        $this->assertTrue($consumedAt->equalTo($reservation->consumed_at));
        $this->assertSame($attempt->public_id, $reservation->attempt_id);
        $this->assertDatabaseCount('exclusive_sales', 0);
    }

    public function test_consumed_promotion_counts_after_expiry_for_new_holds_and_old_attempt_handoff(): void
    {
        $f = $this->finalizedFixture(false, true);
        $consumed = PromotionUse::where('quote_pricing_id', $f['pricing']->id)->sole();
        $this->assertSame('consumed', $consumed->state);
        $prices = app(PriceQuote::class);
        $newQuote = fn () => app(CreateQuote::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $f['items']);
        $oldQuote = $newQuote();
        $oldPricing = $prices->createWithPromotion($oldQuote->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $this->travelTo($oldPricing->expires_at);
        // Default synthetic campaign has three uses: one consumed plus two new held uses.
        foreach ([1, 2] as $unused) {
            $fresh = $newQuote(); $prices->createWithPromotion($fresh->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        }
        $rejected = $newQuote();
        $this->inventoryRejection(fn () => $prices->createWithPromotion($rejected->public_id, InventoryFixtures::OWNER, 'SYNTHETIC'),
            ['PROMOTION_LIMIT_REACHED']);
        // A slower clock cannot revive the expired old hold after its slot was reused.
        $this->travelTo($oldPricing->expires_at->subSecond());
        $this->inventoryRejection(fn () => app(PromotionUsage::class)->beginAttempt($oldQuote->public_id, InventoryFixtures::OWNER, (string) Str::uuid()),
            ['PROMOTION_LIMIT_REACHED']);
        $this->assertSame('consumed', $consumed->refresh()->state);
        $this->assertDatabaseCount('promotion_uses', 4);
        $this->assertSame(1, PromotionUse::where('state', 'consumed')->count());
        $this->assertSame(0, PromotionUse::where('state', 'pending')->count());
    }
}
