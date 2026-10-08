<?php

namespace Tests\Feature\ProductionSuppression;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawalReader;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureTransaction;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionIntents;
use App\Domain\Customers\ProductionFeatures\Suppression\ProductionSuppressionRows;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use Illuminate\Support\Facades\DB;
use ReflectionObject;
use ReflectionProperty;
use Tests\Support\ProductionSuppressionFixtures;
use Tests\TestCase;
use Throwable;

/** C2/C3 conditions applied to 254: only run-minted contexts; private transport input never renders. */
class ProductionSuppressionSealTest extends TestCase
{
    use ProductionSuppressionFixtures;

    private const OWNER = 'suppression-seal@example.test';

    public function test_context_not_minted_by_run_cannot_reach_the_reader_or_254_rows(): void
    {
        $provider = $this->suppressionSetup();
        $owner = $this->featureIdentity('consent_preferences', self::OWNER);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $outcomes = [];
        DB::beginTransaction();
        try {
            $connection = DB::connection();
            $context = ProductionFeatureContext::locked($owner, new ProductionAccountFeatureAccess,
                new CurrentRows($connection->getPdo(), $connection->getDriverName()), new ProductionFeatureTransaction($connection),
                hrtime(true) + 10_000_000_000, new ProductionFeatureConfiguration);
            foreach ([fn () => new ProductionSuppressionRows($context), fn () => (new ProductionConsentWithdrawalReader)->read($context, 1)] as $consumer) {
                try {
                    $consumer();
                    $outcomes[] = 'released';
                } catch (Throwable $error) {
                    $outcomes[] = $error instanceof ProductionFeatureException ? $error->status : $error::class;
                }
            }
        } finally {
            DB::rollBack();
        }
        $this->assertSame([503, 503], $outcomes);
        $this->assertSame([0, 0, 0, 0], array_map('count', array_values($this->suppressionRows())));
        $this->assertSame([], $provider->suppressed);
        // The same owner through the sealed entrypoint is unaffected.
        $this->assertSame(['status' => 'unknown'], (new ProductionSuppressionIntents($provider))->request($owner, 1));
    }

    public function test_transport_request_never_renders_its_recipient(): void
    {
        $provider = $this->suppressionSetup();
        $owner = $this->featureIdentity('consent_preferences', self::OWNER);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        (new ProductionSuppressionIntents($provider))->request($owner, 1);
        $request = $provider->last;
        $this->assertNotNull($request);
        $this->assertSame(self::OWNER, $request->recipient());
        ob_start();
        var_dump($request);
        $renderings = [(string) ob_get_clean(), var_export($request, true), print_r($request, true), var_export((array) $request, true), (string) json_encode((array) $request)];
        foreach ([fn () => json_encode($request, JSON_THROW_ON_ERROR), fn () => serialize($request)] as $render) {
            try {
                $renderings[] = (string) $render();
            } catch (Throwable $error) {
                $renderings[] = $error->getMessage();
            }
        }
        foreach ($renderings as $rendering) {
            $this->assertStringNotContainsString(self::OWNER, $rendering);
            $this->assertStringNotContainsString('suppression-seal', $rendering);
        }
        $this->assertSame([], array_filter((new ReflectionObject($request))->getProperties(), fn (ReflectionProperty $property) => ! $property->isStatic()));
        $this->assertSame(['production_suppression_request' => true], $request->__debugInfo());
        $exposed = null;
        try {
            $exposed = (clone $request)->recipient();
        } catch (Throwable $error) {
            $this->assertStringNotContainsString(self::OWNER, $error->getMessage());
        }
        $this->assertNull($exposed);
    }
}
