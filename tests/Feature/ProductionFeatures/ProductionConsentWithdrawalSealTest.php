<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawal;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawalReader;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use ReflectionObject;
use ReflectionProperty;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

/** Review finding C3: the private recipient is reachable only through serverSnapshot(). */
class ProductionConsentWithdrawalSealTest extends TestCase
{
    use ProductionFeatureFixtures;

    private const RECIPIENT = 'sealed-withdrawal@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_dump_cast_export_json_and_serialize_never_contain_the_recipient(): void
    {
        $withdrawal = $this->withdrawal();
        $this->assertSame(self::RECIPIENT, $withdrawal->serverSnapshot()['recipient']);
        ob_start();
        var_dump($withdrawal);
        $dumped = (string) ob_get_clean();
        $renderings = ['var_export' => var_export($withdrawal, true), 'print_r' => print_r($withdrawal, true), 'var_dump' => $dumped,
            'array cast' => var_export((array) $withdrawal, true), 'get_object_vars' => var_export(get_object_vars($withdrawal), true),
            'json array cast' => (string) json_encode((array) $withdrawal), 'serialize array cast' => serialize((array) $withdrawal)];
        foreach (['json' => fn () => json_encode($withdrawal, JSON_THROW_ON_ERROR), 'serialize' => fn () => serialize($withdrawal)] as $name => $render) {
            try {
                $renderings[$name] = (string) $render();
            } catch (Throwable $error) {
                $renderings[$name] = $error->getMessage();
            }
        }
        foreach ($renderings as $name => $rendering) {
            $this->assertStringNotContainsString(self::RECIPIENT, $rendering, $name);
            $this->assertStringNotContainsString('sealed-withdrawal', $rendering, $name);
        }
        $this->assertSame([], (array) $withdrawal);
        $instance = array_filter((new ReflectionObject($withdrawal))->getProperties(), fn (ReflectionProperty $property) => ! $property->isStatic());
        $this->assertSame([], $instance, 'No instance property carries the private capture.');
        $this->assertSame(['production_consent_withdrawal' => true], $withdrawal->__debugInfo());
    }

    public function test_clones_and_crafted_unserialized_instances_carry_no_capture(): void
    {
        $withdrawal = $this->withdrawal();
        foreach ([fn () => clone $withdrawal, fn () => unserialize('O:'.strlen(ProductionConsentWithdrawal::class).':"'.ProductionConsentWithdrawal::class.'":0:{}')] as $copy) {
            $exposed = null;
            try {
                $exposed = $copy()->serverSnapshot();
            } catch (Throwable $error) {
                $this->assertStringNotContainsString(self::RECIPIENT, $error->getMessage());
            }
            $this->assertNull($exposed, 'Only the sealed original instance exposes its server snapshot.');
        }
        $this->assertSame(self::RECIPIENT, $withdrawal->serverSnapshot()['recipient']);
    }

    private function withdrawal(): ProductionConsentWithdrawal
    {
        $owner = $this->featureIdentity('consent_preferences', self::RECIPIENT);
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());

        return (new ProductionFeatureOperation)->run($owner, fn (ProductionFeatureContext $context): array => ['withdrawal' => (new ProductionConsentWithdrawalReader)->read($context, 1)])['withdrawal'];
    }
}
