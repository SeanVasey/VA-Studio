<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionConfiguration;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionIntents;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Support\Facades\Route;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\Support\RecordingSuppressionProvider;
use Tests\TestCase;
use Throwable;

/** Boot-level proof that the shipped 254 parent is closed, default-off and unbound. */
final class ProductionSuppressionDefaultConfigurationTest extends TestCase
{
    use ProductionSuppressionFixtures;

    private const SHIPPED = ['enabled' => false, 'provider' => null];

    public function test_booted_parent_is_the_exact_closed_default_off_plain_array(): void
    {
        $this->assertSame(self::SHIPPED, require base_path('config/production-suppression.php'));
        $this->assertSame(self::SHIPPED, config('production-suppression'));
        $captured = ProductionSuppressionConfiguration::capture(new ProductionFeatureConfiguration);
        $this->assertSame(['configuration' => self::SHIPPED, 'enabled' => false, 'provider' => null, 'hash' => null], $captured);
        foreach (Route::getRoutes() as $route) {
            $this->assertStringNotContainsString('suppression', strtolower($route->uri().' '.$route->getName().' '.$route->getActionName()), 'No 254 route is mounted.');
        }
    }

    public function test_production_environment_refuses_with_shipped_defaults_before_any_row_or_provider_call(): void
    {
        $provider = new RecordingSuppressionProvider(hash('sha256', 'any'));
        $this->featureSetup();
        config(['production-suppression' => self::SHIPPED]);
        $owner = $this->featureIdentity('consent_preferences', 'suppression-defaults@example.test');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $intents = new ProductionSuppressionIntents($provider);
        $calls = [fn () => $intents->request($owner, 1), fn () => $intents->reconcile($owner), fn () => $intents->status($owner),
            fn () => (new ProductionSuppressionIntents)->request($owner, 1)];
        foreach ($calls as $call) {
            $this->assertSame(503, $this->refusal($call), 'Shipped default-off parent refuses even a rehearsal identity.');
        }
        $this->app['env'] = 'production';
        $this->assertTrue(app()->environment('production'));
        foreach ($calls as $call) {
            $this->assertContains($this->refusal($call), [503, IdentityException::class]);
        }
        $this->assertSame([0, 0, 0, 0], array_map('count', array_values($this->suppressionRows())));
        $this->assertSame([[], []], [$provider->suppressed, $provider->inspected]);
    }

    private function refusal(callable $call): int|string
    {
        try {
            $call();
        } catch (ProductionFeatureException $error) {
            return $error->status;
        } catch (Throwable $error) {
            return $error::class;
        }
        $this->fail('Expected a refusal.');
    }
}
