<?php

namespace Tests\Feature\ProductionCheckoutComposition;

use App\Http\ProductionCheckout\ProductionChainExclusivity;
use App\Http\ProductionCheckout\ProductionCheckoutMount;
use App\Http\Tax\TaxHttpMount;
use App\Providers\ProductionCheckoutServiceProvider;
use App\Providers\ProductionTaxCheckoutServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DD-14: the tax-refusing chain (production_checkout) and the tax-calculating chain (production-tax-checkout) are never
 * admitted on one host. Each mount alone behaves as before; both literal-true admits neither, in every environment.
 */
final class ProductionChainExclusivityTest extends TestCase
{
    private mixed $v1 = false;

    private mixed $tax = false;

    private string $environment = 'testing';

    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(self::class);
        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $app['env'] = $this->environment;
            $app['config']->set('production_checkout.mounted', $this->v1);
            $app['config']->set('production-tax-checkout.http_mounted', $this->tax);
        });
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_shipped_configuration_keeps_both_mounts_literal_false(): void
    {
        $this->assertFalse((require base_path('config/production_checkout.php'))['mounted']);
        $this->assertFalse((require base_path('config/production-tax-checkout.php'))['http_mounted']);
    }

    public function test_the_tax_chain_alone_is_admitted_as_before(): void
    {
        $this->tax = true;
        $this->refreshApplication();
        $this->assertTrue(TaxHttpMount::admitted('production-tax-checkout.http_mounted'));
        $this->assertFalse(ProductionCheckoutMount::admitted());
        $this->assertTrue(Route::has('production.tax-checkout.previews'));
        $this->assertFalse(Route::has('production.checkout.reviews'));
        $this->assertNotNull($this->app->getProvider(ProductionTaxCheckoutServiceProvider::class));
        $this->assertNull($this->app->getProvider(ProductionCheckoutServiceProvider::class));
    }

    public function test_the_tax_refusing_chain_alone_is_admitted_as_before(): void
    {
        $this->v1 = true;
        $this->refreshApplication();
        $this->assertTrue(ProductionCheckoutMount::admitted());
        $this->assertFalse(TaxHttpMount::admitted('production-tax-checkout.http_mounted'));
        $this->assertTrue(Route::has('production.checkout.reviews'));
        $this->assertFalse(Route::has('production.tax-checkout.previews'));
        $this->assertNotNull($this->app->getProvider(ProductionCheckoutServiceProvider::class));
        $this->assertNull($this->app->getProvider(ProductionTaxCheckoutServiceProvider::class));
    }

    /** @return array<string, array{0: string}> */
    public static function environments(): array
    {
        return ['local' => ['local'], 'testing' => ['testing'], 'production' => ['production'], 'staging' => ['staging']];
    }

    #[DataProvider('environments')]
    public function test_both_literal_true_admits_neither_chain(string $environment): void
    {
        $this->v1 = true;
        $this->tax = true;
        $this->environment = $environment;
        $this->refreshApplication();
        $this->assertFalse(ProductionCheckoutMount::admitted());
        $this->assertFalse(TaxHttpMount::admitted('production-tax-checkout.http_mounted'));
        $this->assertFalse(Route::has('production.checkout.reviews'));
        $this->assertFalse(Route::has('production.tax-checkout.previews'));
        $this->assertNull($this->app->getProvider(ProductionCheckoutServiceProvider::class));
        $this->assertNull($this->app->getProvider(ProductionTaxCheckoutServiceProvider::class));
        $this->postJson('/production/checkout/reviews', ['candidateId' => 1])->assertNotFound();
        $this->postJson('/production/tax-checkout/previews', ['candidateId' => 1])->assertNotFound();
    }

    public function test_a_runtime_conflict_withdraws_both_chains_from_stale_routes(): void
    {
        $this->v1 = true;
        $this->refreshApplication();
        $this->assertTrue(ProductionCheckoutMount::admitted());
        config(['production-tax-checkout.http_mounted' => true]);
        $this->assertFalse(ProductionCheckoutMount::admitted());
        $this->assertFalse(TaxHttpMount::admitted('production-tax-checkout.http_mounted'));
        $this->postJson('/production/checkout/reviews', ['candidateId' => 1])->assertNotFound();
    }

    public function test_the_conflict_needs_both_literal_true_and_the_guard_leaves_other_tax_mounts_alone(): void
    {
        foreach ([[true, 'true'], [true, 1], ['true', true], [false, true], [true, null]] as [$v1, $tax]) {
            config(['production_checkout.mounted' => $v1, 'production-tax-checkout.http_mounted' => $tax]);
            $this->assertFalse(ProductionChainExclusivity::conflict(config()), json_encode([$v1, $tax]));
        }
        config(['production_checkout.mounted' => true, 'production-tax-checkout.http_mounted' => true]);
        $this->assertTrue(ProductionChainExclusivity::conflict(config()));
        // The paid-tax-grants mount shares TaxHttpMount but is not a checkout chain; the conflict never touches it.
        config(['paid-tax-grants.http_mounted' => true]);
        $this->assertTrue(TaxHttpMount::admitted('paid-tax-grants.http_mounted'));
    }
}
