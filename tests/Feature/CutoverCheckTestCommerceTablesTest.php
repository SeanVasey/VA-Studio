<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Support\Diagnostics\CutoverLedgerChecks as Checks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * BL-49, ledger PCL-13: "the test_* tables are empty in production". The rows are made by the real test-commerce chain
 * (a synthetic order taken through payment, finalization and fulfillment activation), because guards and foreign keys
 * refuse a hand-written row. The empty state is asserted in CutoverCheckTest, where nothing has run that chain.
 */
class CutoverCheckTestCommerceTablesTest extends TestCase
{
    // The domain services own standalone transactions, so no test transaction may wrap them.
    use FinalizationDatabaseMigrations;

    public function test_rows_left_by_the_test_commerce_chain_fail_in_production_and_are_expected_on_staging(): void
    {
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        DeliveryFixtures::configure();
        Queue::fake();
        $gateway = PaymentFixtures::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);

        $empty = $this->row(Checks::PRODUCTION);
        $this->assertSame(['status' => Checks::PASS, 'detail' => '0 test_* table(s) hold rows'], $empty['tables']);

        DeliveryFixtures::ready($gateway);
        $held = array_values(array_filter(array_column(DB::getSchemaBuilder()->getTables(), 'name'),
            fn (string $name): bool => str_starts_with($name, 'test_') && DB::table($name)->exists()));
        sort($held);
        $this->assertContains('test_fulfillment_activations', $held);
        $this->assertContains('test_delivery_controls', $held);

        $production = $this->row(Checks::PRODUCTION);
        $this->assertSame(['status' => Checks::FAIL, 'detail' => count($held).' test_* table(s) hold rows: '.implode(', ', $held)], $production['tables']);
        // The fixture switches the test chain on, which production must never read as on.
        $this->assertSame(Checks::FAIL, $production['flags']['status']);
        $this->assertStringContainsString('STRIPE_TEST_CHECKOUT_ENABLED', $production['flags']['detail']);
        $this->assertSame([1, Checks::FAIL], [$production['exit'], $production['all']['PCL-13']['status']]);

        // With every flag off again, the retained rows alone still fail production.
        config(array_fill_keys(Checks::TEST_COMMERCE_FLAGS, false));
        $rowsOnly = $this->row(Checks::PRODUCTION);
        $this->assertSame([Checks::PASS, Checks::FAIL, Checks::FAIL], [$rowsOnly['flags']['status'], $rowsOnly['tables']['status'], $rowsOnly['all']['PCL-13']['status']]);

        $staging = $this->row(Checks::STAGING);
        $this->assertSame(Checks::PASS, $staging['all']['PCL-13']['status']);
        $this->assertSame(['status' => Checks::PASS, 'detail' => $production['tables']['detail']], $staging['tables']);

        // A catalog and order history that is not the synthetic rehearsal catalog is not mistaken for it.
        $this->assertSame(Checks::PASS, $production['all']['PCL-02']['status']);
        $this->assertSame(Checks::PASS, $production['all']['PCL-03']['status']);
    }

    private function row(string $profile): array
    {
        $exit = Artisan::call('vasey:cutover-check', ['--expect' => $profile, '--json' => true]);
        $all = array_column(json_decode(Artisan::output(), true, 16, JSON_THROW_ON_ERROR)['rows'], null, 'id');
        [$flags, $tables] = $all['PCL-13']['findings'];

        return compact('exit', 'all', 'flags', 'tables');
    }
}
