<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentEvent;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PDO;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;

class ProductionFeatureSourceClosureTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_genuine_lazy_secondary_resolver_cannot_rebind_a_saved_private_projection(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $before = (array) DB::table('production_listening_libraries')->sole();
        $fired = false;
        LibraryRow::saved(function (LibraryRow $model) use (&$fired) {
            $this->lazy(function () use (&$fired, $model) {
                $fired = true;
                $state = json_decode(Crypt::decryptString($model->payload), true, 32, JSON_THROW_ON_ERROR);
                $state['version']++;
                $state['playlists'][0]['name'] = 'SYNTHETIC private resolver rebound name';
                DB::table('production_listening_libraries')->where('id', $model->id)->update([
                    'version' => $state['version'], 'payload' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR)),
                ]);
            });
        });
        try {
            $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC intended private name']);
            $this->fail('Source resolution cannot approve a different retained revision.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired, 'Exercise the actual lazy secondary PDO resolver.');
            $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
        } finally {
            LibraryRow::flushEventListeners();
            DB::purge('production_feature_secondary');
        }
    }

    public function test_source_resolver_after_a_consent_save_cannot_withdraw_purpose_after_its_guard(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        $fired = false;
        ProductionConsentEvent::saved(function () use (&$fired) {
            $this->lazy(function () use (&$fired) {
                $fired = true;
                config(['production-customer-preferences.grants_enabled' => false,
                    'production-customer-preferences.email_marketing' => null]);
            });
        });
        try {
            $preferences->change($owner, $command);
            $this->fail('Late source resolution must precede the final purpose guard.');
        } catch (ConsentException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired);
            $this->assertSame(0, DB::table('production_consent_events')->count());
            $this->assertSame(0, DB::table('production_consent_states')->count());
        } finally {
            ProductionConsentEvent::flushEventListeners();
            DB::purge('production_feature_secondary');
        }
    }

    public function test_postcommit_unresolved_secondary_is_unknown_without_running_its_callback_or_resetting_write(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $registered = false;
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use (&$registered, &$fired) {
            if (! $registered) {
                $registered = true;
                $this->lazy(function () use (&$fired) {
                    $fired = true;
                    config(['production-account-features.enabled' => false]);
                });
            }
        });
        try {
            $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC original durable write']);
            $this->fail('A postcommit unresolved source cannot release a private response.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($registered);
            $this->assertFalse($fired, 'Committed proof refuses the unresolved PDO without invoking it.');
            $row = (array) DB::table('production_listening_libraries')->sole();
            $this->assertSame(1, (int) $row['version']);
            $this->assertSame('SYNTHETIC original durable write', json_decode(Crypt::decryptString($row['payload']), true)['playlists'][0]['name']);
        } finally {
            DB::purge('production_feature_secondary');
        }
    }

    private function lazy(callable $callback): void
    {
        config(['database.connections.production_feature_secondary' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $connection = DB::connection('production_feature_secondary');
        $connection->setPdo(function () use ($callback): PDO {
            $callback();

            return new PDO('sqlite::memory:');
        });
    }
}
