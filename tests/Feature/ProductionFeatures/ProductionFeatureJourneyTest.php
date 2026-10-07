<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;

class ProductionFeatureJourneyTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_explicit_library_initializer_and_monotonic_clear_do_not_adopt_or_rewrite_v1(): void
    {
        $identity = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $this->assertSame(['initialized' => false], $library->read($identity));
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        $initial = $library->initialize($identity);
        $this->assertSame(0, $initial['library']['version']);
        $this->assertSame($initial, $library->initialize($identity));
        $result = $library->change($identity, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC private song ideas']);
        $this->assertSame(1, $result['library']['version']);
        $raw = (array) DB::table('production_listening_libraries')->sole();
        $state = json_decode(Crypt::decryptString($raw['payload']), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $state['schema']);
        $this->assertStringNotContainsString('SYNTHETIC private song ideas', $raw['payload']);
        try {
            $library->initialize($identity);
            $this->fail('Nonpristine initialization must refuse.');
        } catch (ProductionFeatureException $e) {
            $this->assertSame(409, $e->status);
        }
        $export = $library->export($identity, 1);
        $this->assertSame('SYNTHETIC private song ideas', $export['playlists'][0]['name']);
        $this->assertArrayNotHasKey('accountId', $export);
        $clear = $library->change($identity, ['action' => 'clear-library', 'version' => 1]);
        $this->assertSame(2, $clear['library']['version']);
        $this->assertSame([], $clear['library']['playlists']);
        $this->assertSame(1, DB::table('production_account_feature_bindings')->count());
        $this->assertSame(0, DB::table('customer_saved_tracks')->count());
    }

    public function test_unknown_initialize_grant_withdraw_and_repeated_withdraw_retain_production_pointer(): void
    {
        $identity = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $this->assertSame(['initialized' => false], $preferences->read($identity));
        $initial = $preferences->initialize($identity);
        $this->assertSame('unknown', $initial['preferences']['purposes'][0]['status']);
        $this->assertSame($initial, $preferences->initialize($identity));
        $this->assertSame(0, DB::table('production_consent_events')->count());
        $grant = $preferences->change($identity, $this->productionGrant());
        $this->assertSame('granted', $grant['preferences']['purposes'][0]['status']);
        $withdraw = $preferences->change($identity, $this->productionWithdraw(1));
        $this->assertSame('pending', $withdraw['preferences']['purposes'][0]['suppression']['status']);
        $pointer = DB::table('production_consent_states')->value('withdrawal_event_id');
        $again = $preferences->change($identity, $this->productionWithdraw(2));
        $this->assertSame(3, $again['preferences']['purposes'][0]['version']);
        $this->assertNotSame($pointer, DB::table('production_consent_states')->value('withdrawal_event_id'));
        $this->assertSame(3, DB::table('production_consent_events')->count());
        $this->assertSame(0, DB::table('customer_consent_events')->count());
        $this->assertStringNotContainsString('feature-owner@example.test', json_encode($again));
        $this->assertArrayNotHasKey('accountId', $again['preferences']);
    }
}
