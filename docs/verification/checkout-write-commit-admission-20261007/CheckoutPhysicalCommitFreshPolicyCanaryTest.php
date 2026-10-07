<?php
namespace Tests\Canary;
use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Support\Facades\DB;
use Tests\Feature\ProductionCheckoutJourneyTest;
final class CheckoutPhysicalCommitFreshPolicyCanaryTest extends ProductionCheckoutJourneyTest
{
    public function test_physical_committing_policy_withdrawal_prevents_new_order_rows(): void
    {
        $f = $this->payable(false);
        $callbacks = 0;
        app('events')->listen(TransactionCommitting::class, function () use (&$callbacks): void {
            $callbacks++;
            config(['production_checkout.fresh_checkout_enabled' => false]);
        });
        $refused = false;
        try {
            $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-physical-commit-policy');
        } catch (CheckoutException) {
            $refused = true;
        }
        $counts = [];
        foreach (['order', 'line', 'attempt'] as $kind) {
            $counts[$kind] = DB::table(CheckoutSchema::TABLES[$kind])->count();
        }
        file_put_contents('/tmp/va-checkout-physical-commit-snapshot.json', json_encode(['callbacks'=>$callbacks,'refused'=>$refused,'fresh'=>config('production_checkout.fresh_checkout_enabled'),'counts'=>$counts], JSON_PRETTY_PRINT)."\n");
        $this->assertGreaterThan(0, $callbacks);
        $this->assertFalse(config('production_checkout.fresh_checkout_enabled'));
        $this->assertTrue($refused);
        $this->assertSame(['order'=>0,'line'=>0,'attempt'=>0], $counts);
    }
}
