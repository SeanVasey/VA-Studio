<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawal;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentWithdrawalReader;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureOperation;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;

class ProductionConsentWithdrawalReaderTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_reader_keeps_unknown_null_and_retained_withdrawal_after_grants_without_serializing_recipient(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        try {
            $this->capture($owner, 0);
            $this->fail('A read cannot initialize or adopt a feature binding.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame(0, DB::table('production_account_feature_bindings')->count());
        }
        $preferences->initialize($owner);
        $this->assertNull($this->capture($owner, 0));
        $preferences->change($owner, $this->productionWithdraw());
        $first = $this->capture($owner, 1);
        $this->assertInstanceOf(ProductionConsentWithdrawal::class, $first);
        $snapshot = $first->serverSnapshot();
        $this->assertSame('feature-owner@example.test', $snapshot['recipient']);
        $this->assertSame(1, $snapshot['withdrawalRevision']);
        $this->assertSame('consent_preferences', $snapshot['originalFeatureBinding']['feature']);
        $preferences->change($owner, $this->productionGrant(1));
        $preferences->change($owner, $this->productionGrant(2));
        $retained = $this->capture($owner, 3)->serverSnapshot();
        $this->assertSame($snapshot['withdrawalEventId'], $retained['withdrawalEventId']);
        $this->assertSame(3, $retained['consentVersion']);
        $this->assertSame('pending', $preferences->read($owner)['preferences']['purposes'][0]['suppression']['status']);
        try {
            $this->capture($owner, 2);
            $this->fail('Stale version cannot select a withdrawal for a new intent.');
        } catch (ConsentException $error) {
            $this->assertSame(409, $error->status);
        }
        foreach ([fn () => json_encode($first, JSON_THROW_ON_ERROR), fn () => serialize($first)] as $serialize) {
            try {
                $serialize();
                $this->fail('Private recipient capture must not become a client projection.');
            } catch (LogicException $error) {
                $this->assertStringNotContainsString($snapshot['recipient'], $error->getMessage());
            }
        }
        $this->assertSame(['purpose' => 'email_marketing', 'version' => 1], $first->__debugInfo());
        $this->assertSame(3, DB::table('production_consent_events')->count());
    }

    public function test_two_real_origins_cannot_read_anothers_withdrawal_or_forge_its_actor(): void
    {
        $alice = $this->featureIdentity('consent_preferences', 'withdrawal-alice@example.test');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($alice);
        $preferences->change($alice, $this->productionWithdraw());
        $aliceSnapshot = $this->capture($alice, 1)->serverSnapshot();
        $bob = $this->featureIdentity('consent_preferences', 'withdrawal-bob@example.test');
        $preferences->initialize($bob);
        $this->assertNull($this->capture($bob, 0));
        $preferences->change($bob, $this->productionWithdraw());
        $bobSnapshot = $this->capture($bob, 1)->serverSnapshot();
        $this->assertSame('withdrawal-bob@example.test', $bobSnapshot['recipient']);
        $this->assertNotSame($aliceSnapshot['bindingId'], $bobSnapshot['bindingId']);
        $this->assertNotSame($aliceSnapshot['withdrawalEventId'], $bobSnapshot['withdrawalEventId']);
        $alice->actor()->setRawAttributes($bob->actor()->getAttributes(), true);
        try {
            $this->capture($alice, 1);
            $this->fail('A sealed origin does not authorize a different current actor.');
        } catch (IdentityException) {
            $this->assertSame(2, DB::table('production_consent_events')->count());
        }
    }

    public function test_recovery_preserves_signed_original_withdrawal_and_refuses_old_session(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $old = $this->capture($owner, 1)->serverSnapshot();
        $binding = (array) DB::table('production_account_feature_bindings')->sole();
        $this->completeIdentity($this->requestIdentity('recover', 'feature-owner@example.test'), 'RecoveredWithdrawalPassword456');
        try {
            $this->capture($owner, 1);
            $this->fail('Pre-recovery access must not renew through retained withdrawal history.');
        } catch (IdentityException) {
            $this->assertTrue(true);
        }
        $verified = (new ProductionCustomerSessions)->authenticate('feature-owner@example.test', 'RecoveredWithdrawalPassword456');
        $this->assertNotNull($verified);
        $fresh = $this->featureFor($verified, 'consent_preferences');
        $this->assertSame($old, $this->capture($fresh, 1)->serverSnapshot());
        $this->assertSame($binding, (array) DB::table('production_account_feature_bindings')->sole());
    }

    public function test_postcommit_credential_withdrawal_cannot_release_the_private_server_capture(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $events = DB::table('production_consent_events')->get()->map(fn ($row) => (array) $row)->all();
        $fired = false;
        Event::listen(TransactionCommitted::class, function () use ($owner, &$fired): void {
            if (! $fired) {
                $fired = true;
                DB::table('users')->where('id', $owner->principal()->userId)->update(['password' => Hash::make('WithdrawalCaptureRefused456')]);
            }
        });
        try {
            $this->capture($owner, 1);
            $this->fail('No private recipient snapshot may escape after commit-event withdrawal.');
        } catch (ProductionFeatureException $error) {
            $this->assertSame(503, $error->status);
            $this->assertTrue($fired);
            $this->assertSame($events, DB::table('production_consent_events')->get()->map(fn ($row) => (array) $row)->all());
        }
    }

    private function capture(ProductionAccountFeatureIdentity $identity, int $version): ?ProductionConsentWithdrawal
    {
        return (new ProductionFeatureOperation)->run($identity, fn (ProductionFeatureContext $context): array => ['withdrawal' => (new ProductionConsentWithdrawalReader)->read($context, $version)])['withdrawal'];
    }
}
