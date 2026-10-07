<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionAccountFeatureAccessTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public function test_received_mailbox_identity_and_marker_bind_new_features_without_credential_or_owner_projection(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $request = $this->requestFor($identity);
        $this->enable();
        $access = new ProductionAccountFeatureAccess;
        foreach (array_keys(ProductionAccountFeaturePolicy::VERSIONS) as $feature) {
            $owner = $access->forRequest($request, $feature);
            $binding = $access->durableBinding($owner);
            $this->assertSame($feature, $binding['feature']);
            $this->assertSame($identity['binding'], $binding['buyer_binding']);
            $this->assertSame(['feature_schema_version', 'feature', 'feature_policy_version', 'buyer_binding'], array_keys($binding));
            $this->assertStringNotContainsString('owner_key', json_encode($binding));
            $this->assertStringNotContainsString('credential', json_encode($binding));
            $this->assertStringNotContainsString('buyer@example.test', json_encode($binding));
            DB::transaction(function () use ($access, $owner, $binding): void {
                $reader = $this->reader();
                $current = $access->lock($owner, $reader);
                $original = $access->verifyOriginalBinding($owner, $binding, $reader);
                $access->proveOriginalBindingCurrent($owner, $binding, $reader, $original);
                $access->proveCurrent($owner, $reader, $current);
            });
        }
    }

    public function test_recovery_preserves_original_feature_act_but_an_old_session_cannot_remint_current_authority(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $request = $this->requestFor($identity);
        $this->enable();
        $access = new ProductionAccountFeatureAccess;
        $owner = $access->forRequest($request, 'service_projects');
        $binding = $access->durableBinding($owner);
        $challenge = $this->requestIdentity('recover');
        $this->completeIdentity($challenge, 'RecoveredPassword456');
        try {
            $access->forRequest($request, 'service_projects');
            $this->fail('The pre-recovery marker must not remint feature authority.');
        } catch (IdentityException) {
            $this->assertTrue(true);
        }
        $fresh = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'RecoveredPassword456');
        $this->assertNotNull($fresh);
        $request = $this->requestFor($fresh);
        $currentOwner = $access->forRequest($request, 'service_projects');
        $this->assertNotSame($binding['buyer_binding']['verification_observation_id'], $access->durableBinding($currentOwner)['buyer_binding']['verification_observation_id']);
        DB::transaction(function () use ($access, $currentOwner, $binding): void {
            $reader = $this->reader();
            $current = $access->lock($currentOwner, $reader);
            $original = $access->verifyOriginalBinding($currentOwner, $binding, $reader);
            $access->proveOriginalBindingCurrent($currentOwner, $binding, $reader, $original);
            $access->proveCurrent($currentOwner, $reader, $current);
        });
    }

    public static function withdrawals(): array
    {
        return [['credential'], ['feature_flag']];
    }

    #[DataProvider('withdrawals')]
    public function test_module_callbacks_cannot_commit_after_current_identity_or_feature_withdrawal(string $withdrawal): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $request = $this->requestFor($identity);
        $this->enable();
        $access = new ProductionAccountFeatureAccess;
        $owner = $access->forRequest($request, 'listening_library');
        $oldPassword = $identity['user']->getAuthPassword();
        $nextPassword = Hash::make('WithdrawnCredential456');
        try {
            DB::transaction(function () use ($access, $owner, $withdrawal, $nextPassword): void {
                $reader = $this->reader();
                $current = $access->lock($owner, $reader);
                if ($withdrawal === 'credential') {
                    DB::table('users')->where('id', $owner->principal()->userId)->update(['password' => $nextPassword]);
                } else {
                    config(['production-account-features.enabled' => false]);
                }
                $access->proveCurrent($owner, $reader, $current);
            });
            $this->fail('A withdrawn feature/identity must not commit.');
        } catch (IdentityException) {
            $this->assertSame($oldPassword, $identity['user']->fresh()->getAuthPassword());
        }
    }

    public function test_unbound_old_data_cross_feature_owner_and_provenance_bindings_are_refused(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $request = $this->requestFor($identity);
        $this->enable();
        $access = new ProductionAccountFeatureAccess;
        $owner = $access->forRequest($request, 'listening_library');
        $binding = $access->durableBinding($owner);
        $otherOwner = $binding;
        $otherOwner['buyer_binding']['origin_id'] = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
        $otherScope = $binding;
        $otherScope['buyer_binding']['provenance'] = IdentityPolicy::PRODUCTION;
        $otherFeature = $binding;
        $otherFeature['feature'] = 'service_projects';
        foreach ([[], ['accountId' => $identity['principal']->accountId], $otherOwner, $otherScope, $otherFeature] as $invalid) {
            try {
                DB::transaction(fn () => $access->verifyOriginalBinding($owner, $invalid, $this->reader()));
                $this->fail('An unbound or mismatched source cannot be adopted.');
            } catch (IdentityException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_default_off_missing_marker_unknown_feature_and_serialization_are_refused(): void
    {
        $identity = $this->enrollThroughLocalSmtp();
        $request = $this->requestFor($identity);
        $access = new ProductionAccountFeatureAccess;
        try {
            $access->forRequest($request, 'listening_library');
            $this->fail('Features must default off.');
        } catch (IdentityException) {
            $this->assertTrue(true);
        }
        $this->enable();
        $request->session()->forget('_production_customer_identity');
        foreach (['listening_library', 'legacy_test_commerce'] as $feature) {
            try {
                $access->forRequest($request, $feature);
                $this->fail('A new marker and known version are mandatory.');
            } catch (IdentityException) {
                $this->assertTrue(true);
            }
        }
        $request = $this->requestFor($identity);
        $owner = $access->forRequest($request, 'listening_library');
        foreach ([fn () => serialize($owner), fn () => json_encode($owner)] as $projection) {
            try {
                $projection();
                $this->fail('Current feature identity is private.');
            } catch (\LogicException) {
                $this->assertTrue(true);
            }
        }
    }

    private function enable(): void
    {
        config(['production-account-features.enabled' => true, 'production-account-features.provenance' => IdentityPolicy::REHEARSAL,
            'production-account-features.versions' => ProductionAccountFeaturePolicy::VERSIONS]);
    }

    private function requestFor(array $identity): Request
    {
        Auth::guard('customer')->setUser($identity['user']);
        $request = Request::create('/customer');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $identity['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard ?? 'web')->user());

        return $request;
    }

    private function reader(): CurrentRows
    {
        return new CurrentRows(DB::connection()->getPdo(), DB::getDriverName());
    }
}
