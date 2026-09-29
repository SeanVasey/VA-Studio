<?php

namespace Tests\Feature;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\PromotionUse;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\PromotionAdministration;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\PromotionFixtures;
use Tests\TestCase;

class PromotionAdministrationSettlementTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public function test_disabling_preserves_pending_orders_replays_and_verified_consumption_without_resetting_usage(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); FinalizationFixtures::configure(); Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $actor = LicenseFixtures::admin(); $service = app(PromotionAdministration::class);
        $campaign = $service->create(PromotionFixtures::policy(['max_uses' => 2]), $actor);
        $service->setAvailability($campaign->id, 1, true, $actor);
        $fixture = InventoryFixtures::selection();
        app(PriceQuote::class)->createWithPromotion($fixture['quote']->public_id, InventoryFixtures::OWNER, 'SYNTHETIC');
        $request = OrderFixtures::request($fixture['quote']); $key = (string) Str::uuid();
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, $key, $request);
        $before = app(ReadOrder::class)->verify($order);
        $service->setAvailability($campaign->id, 2, false, $actor);
        $this->assertSame('pending', PromotionUse::sole()->state);
        $this->assertSame($order->id, app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, $key, $request)->id);
        $this->assertSame($before, app(ReadOrder::class)->verify($order->fresh()));
        $this->assertSame(['held' => 0, 'pending' => 1, 'consumed' => 0, 'expired' => 0, 'remaining' => 1], $service->detail($campaign->id, $actor)['usage']);

        // A second managed promotion use has already entered provider verification when the operator disables it.
        $service->setAvailability($campaign->id, 3, true, $actor);
        $confirmed = FinalizationFixtures::confirmed($gateway, false, true);
        $service->setAvailability($campaign->id, 4, false, $actor);
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($confirmed['payment']->id));
        $this->assertSame($confirmed['original'], app(ReadOrder::class)->verify($confirmed['order']->fresh()));
        $this->assertSame('paid', app(FinalizeTestPayment::class)->handle($confirmed['payment']->id));
        $usage = $service->detail($campaign->id, $actor)['usage'];
        $this->assertSame(['held' => 0, 'pending' => 1, 'consumed' => 1, 'expired' => 0, 'remaining' => 0], $usage);
        $service->setAvailability($campaign->id, 5, true, $actor);
        $this->assertSame($usage, $service->detail($campaign->id, $actor)['usage']);
        $this->assertDatabaseCount('promotion_campaigns', 1); $this->assertDatabaseCount('promotion_uses', 2);
    }
}
