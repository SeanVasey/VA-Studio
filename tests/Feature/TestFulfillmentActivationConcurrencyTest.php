<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Delivery\Models\TestFulfillmentActivation;
use App\Domain\Delivery\ReadTestFulfillmentActivation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ActivationFixtures as F;
use Tests\Support\ActivationRace;
use Tests\Support\ContractFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class TestFulfillmentActivationConcurrencyTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') { $this->markTestSkipped('Activation creation races require independent MySQL processes.'); }
    }

    public function test_independent_creators_that_both_verify_a_complete_order_converge_on_one_proof_and_audit(): void
    {
        $this->fakePrivateMediaStorage(); $this->travelTo(now()->startOfSecond()); F::configure(); Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway); $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $f = F::issued($gateway, true); $before = ContractFixtures::retained();
        $results = ActivationRace::run($this, ['order_id' => $f['order']->id, 'now' => now()->toIso8601ZuluString()]);
        $this->assertSame(['activated', 'activated'], array_column($results, 'outcome'));
        $proof = TestFulfillmentActivation::sole();
        $this->assertSame([$proof->public_id, $proof->public_id], array_column($results, 'activation_id'));
        $this->assertSame(1, DB::table('audit_events')->where('action', 'commerce.fulfillment.test_activated')->count());
        $this->assertSame($proof->getAttributes(), app(ReadTestFulfillmentActivation::class)->forOrder($f['order']->fresh())->getAttributes());
        $this->assertSame($before, ContractFixtures::retained());
    }
}
