<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Catalog\Models\Track;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Listening\SavedListeningLibrary;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Listening\ProductionListeningLibrary;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentEvent;
use App\Domain\Customers\ProductionFeatures\Models\ProductionListeningLibrary as LibraryRow;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\Support\PreviousListeningV1;
use Tests\Support\ProductionFeatureFixtures;
use Tests\Support\QuoteFixtures;
use Tests\TestCase;

class ProductionFeatureBoundaryTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
        $this->fakePrivateMediaStorage();
    }

    public function test_unbound_legacy_private_library_is_refused_and_preserved_by_every_entrypoint(): void
    {
        $owner = $this->featureIdentity();
        $legacy = SavedListeningLibrary::create(['customer_account_id' => $owner->principal()->accountId, 'version' => 1,
            'payload' => ['schema' => 1, 'accountId' => $owner->principal()->accountId, 'version' => 1, 'favorites' => [],
                'playlists' => [['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'name' => 'SYNTHETIC unbound historical private name', 'trackIds' => []]]]]);
        $before = SavedListeningLibrary::sole()->getRawOriginal();
        $library = new ProductionListeningLibrary;
        foreach ([fn () => $library->read($owner), fn () => $library->initialize($owner),
            fn () => $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC next']), fn () => $library->export($owner, 0)] as $call) {
            $this->refusesFeature($call, 409);
        }
        $this->assertSame($before, SavedListeningLibrary::sole()->getRawOriginal());
        $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
    }

    public function test_two_actual_origins_cannot_read_rename_or_rebind_each_others_library(): void
    {
        $first = $this->featureIdentity();
        $second = $this->featureIdentity(email: 'other-feature-owner@example.test');
        $library = new ProductionListeningLibrary;
        $library->initialize($first);
        $private = $library->change($first, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC first owner private name']);
        $this->assertSame(['initialized' => false], $library->read($second));
        $empty = $library->initialize($second);
        $this->assertSame([], $empty['library']['playlists']);
        try {
            $library->change($second, ['action' => 'rename-playlist', 'version' => 0,
                'playlistId' => $private['library']['playlists'][0]['id'], 'name' => 'SYNTHETIC cross-owner attempt']);
            $this->fail('Another owner cannot rename a private playlist.');
        } catch (ListeningException $error) {
            $this->assertSame(404, $error->status);
        }
        $first->actor()->id = $second->principal()->userId;
        $this->expectException(IdentityException::class);
        $library->read($first);
    }

    public function test_real_recovery_keeps_original_binding_and_refuses_pre_recovery_identity(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $private = $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC retained recovery playlist']);
        $binding = (array) DB::table('production_account_feature_bindings')->sole();
        $challenge = $this->requestIdentity('recover', 'feature-owner@example.test');
        $this->completeIdentity($challenge, 'RecoveredFeaturePassword456');
        try {
            $library->read($owner);
            $this->fail('Pre-recovery identity cannot be renewed by this consumer.');
        } catch (IdentityException) {
            $this->assertTrue(true);
        }
        $verified = (new ProductionCustomerSessions)->authenticate('feature-owner@example.test', 'RecoveredFeaturePassword456');
        $this->assertNotNull($verified);
        $fresh = $this->featureFor($verified, 'listening_library');
        $this->assertSame($private, $library->read($fresh));
        $this->assertSame($binding, (array) DB::table('production_account_feature_bindings')->sole());
    }

    public function test_actual_public_catalog_projection_and_all_ordinary_writes_keep_pinned_v1_readable(): void
    {
        $owner = $this->featureIdentity();
        $selection = QuoteFixtures::selection();
        $id = (string) $selection['track']->id;
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        config(['production-customer-listening.v2_promotion_enabled' => true,
            'production-customer-listening.v2_rollout_review_reference' => 'SYNTHETIC stopped-upgrade production fixture']);
        $saved = $library->change($owner, ['action' => 'save-track', 'version' => 0, 'trackId' => $id]);
        $this->assertTrue($saved['library']['favorites'][0]['available']);
        $this->assertSame($selection['track']->title, $saved['library']['favorites'][0]['track']['title']);
        $this->assertPinnedV1($owner->principal()->accountId);
        $playlist = $library->change($owner, ['action' => 'create-playlist', 'version' => 1, 'name' => 'SYNTHETIC ordinary playlist']);
        $playlistId = $playlist['library']['playlists'][0]['id'];
        $version = 2;
        foreach ([['action' => 'add-playlist-track', 'playlistId' => $playlistId, 'trackId' => $id],
            ['action' => 'rename-playlist', 'playlistId' => $playlistId, 'name' => 'SYNTHETIC renamed ordinary playlist'],
            ['action' => 'reorder-playlist', 'playlistId' => $playlistId, 'trackIds' => [$id]],
            ['action' => 'remove-playlist-track', 'playlistId' => $playlistId, 'trackId' => $id],
            ['action' => 'delete-playlist', 'playlistId' => $playlistId]] as $command) {
            $result = $library->change($owner, $command + ['version' => $version]);
            $version = $result['library']['version'];
            $this->assertPinnedV1($owner->principal()->accountId);
        }
        $before = (array) DB::table('production_listening_libraries')->sole();
        $this->assertSame($result, $library->change($owner, ['action' => 'save-track', 'version' => $version, 'trackId' => $id]));
        $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole(), 'Noop preserves exact encrypted bytes and revision.');
        app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
        $unavailable = $library->read($owner);
        $this->assertSame([['trackId' => $id, 'available' => false]], $unavailable['library']['favorites']);
        $library->change($owner, ['action' => 'remove-saved-track', 'version' => $version++, 'trackId' => $id]);
        $this->assertPinnedV1($owner->principal()->accountId);
        $clear = $library->change($owner, ['action' => 'clear-library', 'version' => $version]);
        $this->assertSame($version + 1, $clear['library']['version']);
        $this->assertPinnedV1($owner->principal()->accountId);
    }

    public function test_private_track_cannot_be_discovered_or_saved_and_stale_clear_cannot_resurrect(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $track = Track::create(['title' => 'SYNTHETIC private draft metadata', 'slug' => 'synthetic-private-draft']);
        try {
            $library->change($owner, ['action' => 'save-track', 'version' => 0, 'trackId' => (string) $track->id]);
            $this->fail('Private track is unavailable.');
        } catch (ListeningException $error) {
            $this->assertSame(404, $error->status);
            $this->assertStringNotContainsString($track->title, $error->getMessage());
        }
        $library->change($owner, ['action' => 'clear-library', 'version' => 0]);
        try {
            $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC stale resurrection']);
            $this->fail('Clear retains a monotonic revision fence.');
        } catch (ListeningException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame([], $library->read($owner)['library']['playlists']);
    }

    public static function callbackChanges(): array
    {
        return [['credential'], ['account'], ['feature_policy']];
    }

    #[DataProvider('callbackChanges')]
    public function test_saved_callback_withdrawal_rolls_back_private_library_write(string $change): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $before = (array) DB::table('production_listening_libraries')->sole();
        LibraryRow::saved(function () use ($owner, $change) {
            if ($change === 'credential') {
                DB::table('users')->where('id', $owner->principal()->userId)->update(['password' => Hash::make('WithdrawnFeaturePassword987')]);
            } elseif ($change === 'account') {
                DB::table('customer_accounts')->where('id', $owner->principal()->accountId)->update(['active' => false, 'access_version' => 2]);
            } else {
                config(['production-account-features.enabled' => false]);
            }
        });
        try {
            $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC withheld write']);
            $this->fail('Current authority withdrawal must prevent commit.');
        } catch (IdentityException) {
            $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
        } finally {
            LibraryRow::flushEventListeners();
        }
    }

    public function test_same_saved_model_cannot_rebind_intended_revision_to_another_valid_state(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $before = (array) DB::table('production_listening_libraries')->sole();
        LibraryRow::saved(function (LibraryRow $model) {
            $state = json_decode(Crypt::decryptString($model->payload), true, 32, JSON_THROW_ON_ERROR);
            $state['version']++;
            $state['playlists'][0]['name'] = 'SYNTHETIC rebound state';
            DB::table('production_listening_libraries')->where('id', $model->id)->update(['version' => $state['version'], 'payload' => Crypt::encryptString(json_encode($state, JSON_THROW_ON_ERROR))]);
            $model->refresh();
        });
        try {
            $this->refusesFeature(fn () => $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC intended state']), 503);
            $this->assertSame($before, (array) DB::table('production_listening_libraries')->sole());
        } finally {
            LibraryRow::flushEventListeners();
        }
    }

    public function test_commit_event_withdrawal_returns_unknown_with_original_write_retained(): void
    {
        $owner = $this->featureIdentity();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use (&$fired, $owner) {
            if (! $fired) {
                $fired = true;
                DB::table('users')->where('id', $owner->principal()->userId)->update(['password' => Hash::make('PostcommitWithdrawnPassword456')]);
            }
        });
        $this->refusesFeature(fn () => $library->change($owner, ['action' => 'create-playlist', 'version' => 0, 'name' => 'SYNTHETIC durable unknown write']), 503);
        $this->assertTrue($fired);
        $row = (array) DB::table('production_listening_libraries')->sole();
        $this->assertSame(1, (int) $row['version']);
        $this->assertSame('SYNTHETIC durable unknown write', json_decode(Crypt::decryptString($row['payload']), true)['playlists'][0]['name']);
    }

    public function test_postcommit_public_withdrawal_cannot_release_old_public_link_and_reload_is_fresh(): void
    {
        $owner = $this->featureIdentity();
        $selection = QuoteFixtures::selection();
        $library = new ProductionListeningLibrary;
        $library->initialize($owner);
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use (&$fired, $selection) {
            if (! $fired) {
                $fired = true;
                app(PublishTrack::class)->unpublish($selection['track'], $selection['actor']);
            }
        });
        $id = (string) $selection['track']->id;
        $this->refusesFeature(fn () => $library->change($owner, ['action' => 'save-track', 'version' => 0, 'trackId' => $id]), 503);
        $this->assertTrue($fired);
        $fresh = $library->read($owner);
        $this->assertSame(1, $fresh['library']['version']);
        $this->assertSame([['trackId' => $id, 'available' => false]], $fresh['library']['favorites']);
    }

    public function test_disabled_production_purpose_stays_unknown_but_explicit_withdrawal_remains_monotonic(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        config(['production-customer-preferences' => ['grants_enabled' => false, 'email_marketing' => null],
            'customer-preferences.test_grants_enabled' => true]);
        $read = $preferences->read($owner)['preferences']['purposes'][0];
        $this->assertSame('unknown', $read['status']);
        $this->assertFalse($read['canGrant']);
        try {
            $preferences->change($owner, $command);
            $this->fail('Test purpose policy cannot enable production grants.');
        } catch (ConsentException $error) {
            $this->assertSame(422, $error->status);
        }
        $first = $preferences->change($owner, $this->productionWithdraw());
        $second = $preferences->change($owner, $this->productionWithdraw(1));
        $this->assertSame('withdrawn', $first['preferences']['purposes'][0]['status']);
        $this->assertSame(2, $second['preferences']['purposes'][0]['version']);
        $this->assertSame('pending', $second['preferences']['purposes'][0]['suppression']['status']);
        try {
            $preferences->change($owner, $this->productionWithdraw(1));
            $this->fail('Repeated withdrawal fences stale commands.');
        } catch (ConsentException $error) {
            $this->assertSame(409, $error->status);
        }
        $this->assertSame(2, DB::table('production_consent_events')->count());
    }

    public function test_consent_saved_callback_cannot_commit_after_current_purpose_disables_grants(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $command = $this->productionGrant();
        ProductionConsentEvent::saved(fn () => config(['production-customer-preferences.grants_enabled' => false]));
        try {
            $preferences->change($owner, $command);
            $this->fail('A late purpose withdrawal must roll back the grant.');
        } catch (ConsentException $error) {
            $this->assertSame(503, $error->status);
            $this->assertSame(0, DB::table('production_consent_events')->count());
            $this->assertSame(0, DB::table('production_consent_states')->count());
            $this->assertSame(0, DB::table('production_consent_policies')->count());
        } finally {
            ProductionConsentEvent::flushEventListeners();
        }
    }

    private function refusesFeature(callable $call, int $status): void
    {
        try {
            $call();
            $this->fail('Expected private feature refusal.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame($status, $error->status);
        }
    }

    private function assertPinnedV1(int $accountId): void
    {
        $raw = (array) DB::table('production_listening_libraries')->sole();
        $ephemeral = new SavedListeningLibrary;
        $ephemeral->setRawAttributes(['customer_account_id' => $accountId, 'version' => (int) $raw['version'], 'payload' => $raw['payload']], true);
        $state = (new ReflectionMethod(PreviousListeningV1::reader(), 'state'))->invoke(PreviousListeningV1::reader(), $ephemeral, $accountId);
        $this->assertSame(1, $state['schema']);
        $this->assertSame((int) $raw['version'], $state['version']);
        $this->assertSame(0, DB::table('customer_saved_tracks')->count(), 'Pinned reader uses no persisted legacy record.');
    }
}
