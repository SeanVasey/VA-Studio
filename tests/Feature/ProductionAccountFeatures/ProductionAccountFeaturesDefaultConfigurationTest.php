<?php

namespace Tests\Feature\ProductionAccountFeatures;

use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureConfiguration;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use Illuminate\Http\Request;
use ReflectionReference;
use Tests\Support\ProductionFeatureFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

/** Root-owned default-off parent for the 253 consumers; booted from the shipped config files. */
final class ProductionAccountFeaturesDefaultConfigurationTest extends TestCase
{
    use ProductionFeatureFixtures;

    private const SHIPPED = [
        'production-account-features' => ['enabled' => false, 'provenance' => null, 'versions' => [
            'listening_library' => 'production-listening-library-identity-v1',
            'consent_preferences' => 'production-consent-preferences-identity-v1',
        ]],
        'production-customer-listening' => ['v2_promotion_enabled' => false, 'v2_rollout_review_reference' => null],
        'production-customer-preferences' => ['grants_enabled' => false, 'email_marketing' => null],
    ];

    public function test_booted_parents_are_the_exact_closed_default_off_plain_arrays(): void
    {
        foreach (self::SHIPPED as $parent => $expected) {
            $file = require base_path('config/'.$parent.'.php');
            $this->assertSame($expected, $file, $parent.' file must ship exactly this closed default.');
            $this->assertSame($expected, config($parent), $parent.' must boot exactly from the shipped file.');
            $this->assertPlain($file, $parent);
            $this->assertPlain(config($parent), $parent);
        }
        // Exact reviewed consumer versions come from the canonical identity policy; nothing else is named.
        $this->assertSame(array_intersect_key(ProductionAccountFeaturePolicy::VERSIONS, ['listening_library' => 1, 'consent_preferences' => 1]),
            config('production-account-features.versions'));
        // The raw source reader admits the shipped parent without traversal callbacks.
        $configuration = new ProductionFeatureConfiguration;
        $this->assertSame(self::SHIPPED['production-account-features'], $configuration->snapshot('production-account-features'));
    }

    public function test_production_environment_refuses_every_feature_with_shipped_defaults(): void
    {
        $this->app['env'] = 'production';
        $this->assertTrue(app()->environment('production'));
        foreach (array_keys(ProductionAccountFeaturePolicy::VERSIONS) as $feature) {
            $this->refusesIdentity(fn () => (new ProductionAccountFeaturePolicy($feature))->current());
            $this->refusesIdentity(fn () => (new ProductionAccountFeatureAccess)->forRequest(Request::create('/customer'), $feature));
        }
        // Even a reviewed production identity binding cannot activate features while this parent is shipped off.
        config(['production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::PRODUCTION]);
        $this->assertTrue((new IdentityPolicy)->enabled());
        foreach (array_keys(ProductionAccountFeaturePolicy::VERSIONS) as $feature) {
            $this->refusesIdentity(fn () => (new ProductionAccountFeaturePolicy($feature))->current());
        }
        // Enabling the parent without a matching provenance still refuses.
        config(['production-account-features.enabled' => true]);
        $this->refusesIdentity(fn () => (new ProductionAccountFeaturePolicy('listening_library'))->current());
        // service_projects has no reviewed version in this parent and stays refused even when bound.
        config(['production-account-features.provenance' => IdentityPolicy::PRODUCTION]);
        $this->assertSame('listening_library', (new ProductionAccountFeaturePolicy('listening_library'))->current()['feature']);
        $this->refusesIdentity(fn () => (new ProductionAccountFeaturePolicy('service_projects'))->current());
    }

    public function test_shipped_defaults_refuse_features_notes_promotion_and_grants_through_real_entrypoints(): void
    {
        $this->featureSetup();
        $this->fakePrivateMediaStorage();
        $listeningOwner = $this->featureIdentity();
        $selection = QuoteFixtures::selection();
        $library = new ProductionListeningLibrary;
        $library->initialize($listeningOwner);
        $saved = $library->change($listeningOwner, ['action' => 'save-track', 'version' => 0, 'trackId' => (string) $selection['track']->id]);
        $this->assertSame(1, $saved['library']['listeningSchema']);
        try {
            $library->change($listeningOwner, ['action' => 'set-track-note', 'version' => 1, 'trackId' => (string) $selection['track']->id, 'body' => 'SYNTHETIC note']);
            $this->fail('Shipped note configuration must refuse V2 promotion.');
        } catch (ListeningException $error) {
            $this->assertSame(503, $error->status);
        }
        $consentOwner = $this->featureIdentity('consent_preferences', 'consent-default-owner@example.test');
        $preferences = new ProductionConsentPreferences;
        $read = $preferences->initialize($consentOwner)['preferences']['purposes'][0];
        $this->assertSame(['unknown', false, null], [$read['status'], $read['canGrant'], $read['notice']]);
        $notice = config('production-customer-preferences');
        $command = $this->productionGrant();
        config(['production-customer-preferences' => $notice]);
        try {
            $preferences->change($consentOwner, $command);
            $this->fail('Shipped purpose configuration must refuse grants.');
        } catch (ConsentException $error) {
            $this->assertSame(422, $error->status);
        }
        // Restoring the shipped root parent withdraws both features before any module work.
        config(['production-account-features' => require base_path('config/production-account-features.php')]);
        $this->refusesIdentity(fn () => $library->read($listeningOwner));
        $this->refusesIdentity(fn () => $preferences->read($consentOwner));
    }

    private function refusesIdentity(callable $call): void
    {
        try {
            $call();
            $this->fail('Expected identity refusal under shipped defaults.');
        } catch (IdentityException) {
            $this->addToAssertionCount(1);
        }
    }

    private function assertPlain(mixed $value, string $path): void
    {
        if (! is_array($value)) {
            $this->assertTrue(is_null($value) || is_bool($value) || is_int($value) || is_string($value), $path.' must be a plain scalar.');

            return;
        }
        foreach (array_keys($value) as $key) {
            $this->assertNull(ReflectionReference::fromArrayElement($value, $key), $path.'.'.$key.' must not be a PHP reference.');
            $this->assertPlain($value[$key], $path.'.'.$key);
        }
    }
}
