<?php

namespace Tests\Feature\ProductionFreeIdentity;

use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\FreeGrantRows;
use App\Domain\Grants\Free\Production\ProductionFreeGrantHttpIdentity;
use App\Domain\Grants\Free\Production\ProductionFreeGrantIdentity;
use App\Domain\Grants\Free\Production\ProductionFreeGrantIdentityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionFreeIdentityTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
    }

    public function test_actual_mailbox_owner_has_distinct_safe_free_binding_and_locked_original_proof(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $this->enable();
        $identity = new ProductionFreeGrantIdentity;
        $principal = $identity->principal($owner['user']);
        $binding = $identity->durableBinding($principal);
        $this->assertSame($owner['binding'], $binding['buyer_binding']);
        $this->assertSame('production-free-origin-v1', $binding['purpose']);
        $this->assertSame('synthetic_rehearsal', $binding['provenance']);
        $this->assertFalse($binding['legal_identity_verified']);
        foreach (['owner_key', 'credential', 'password', 'buyer@example.test'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($binding));
        }
        DB::transaction(function () use ($identity, $principal, $owner, $binding): void {
            $rows = new FreeGrantRows;
            $current = $identity->lock($principal, $owner['user'], $rows);
            $original = $identity->lockOriginal($principal, $owner['user'], $binding, $rows);
            $identity->proveOriginalPrimary($principal, $owner['user'], $binding, $rows, $original);
            $identity->provePrimary($principal, $owner['user'], $rows, $current);
        });
        $this->assertDatabaseCount('free_origins', 0);
        $this->assertDatabaseCount('free_originals', 0);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_current_recovery_rejects_old_marker_while_retaining_exact_original_origin_history(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $this->enable();
        $identity = new ProductionFreeGrantIdentity;
        $http = new ProductionFreeGrantHttpIdentity;
        $request = $this->request($owner);
        [$principal, $actor] = $http->forRequest($request);
        $binding = $identity->durableBinding($principal);
        $challenge = $this->requestIdentity('recover');
        $this->completeIdentity($challenge, 'RecoveredFreePassword456');
        try {
            $http->forRequest($request);
            $this->fail('The old marker cannot remint an account after recovery.');
        } catch (FreeGrantException $error) {
            $this->assertSame(403, $error->status);
        }
        $fresh = (new ProductionCustomerSessions)->authenticate('buyer@example.test', 'RecoveredFreePassword456');
        $this->assertNotNull($fresh);
        [$principal, $actor] = $http->forRequest($this->request($fresh));
        $this->assertSame($binding['buyer_binding']['origin_id'], $principal->durableBinding()['origin_id']);
        $this->assertNotSame($binding['buyer_binding']['verification_observation_id'], $principal->durableBinding()['verification_observation_id']);
        DB::transaction(function () use ($identity, $principal, $actor, $binding): void {
            $rows = new FreeGrantRows;
            $current = $identity->lock($principal, $actor, $rows);
            $original = $identity->lockOriginal($principal, $actor, $binding, $rows);
            $identity->proveOriginalPrimary($principal, $actor, $binding, $rows, $original);
            $identity->provePrimary($principal, $actor, $rows, $current);
        });
    }

    public static function terminalWithdrawals(): array
    {
        return [['feature_flag'], ['lazy_secondary'], ['credential']];
    }

    #[DataProvider('terminalWithdrawals')]
    public function test_actual_normal_mutation_rolls_back_on_terminal_policy_credential_or_lazy_connection_withdrawal(string $mode): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $this->enable();
        $identity = new ProductionFreeGrantIdentity;
        $principal = $identity->principal($owner['user']);
        $name = 'production_free_terminal';
        $callbacks = 0;
        DB::unprepared('CREATE TABLE production_free_fixture (id INTEGER PRIMARY KEY)');
        try {
            try {
                DB::transaction(function () use ($identity, $principal, $owner, $mode, $name, &$callbacks): void {
                    $rows = new FreeGrantRows;
                    $current = $identity->lock($principal, $owner['user'], $rows);
                    DB::table('production_free_fixture')->insert(['id' => 1]);
                    if ($mode === 'feature_flag') {
                        config(['production-free-grant-identity.enabled' => false]);
                    } elseif ($mode === 'credential') {
                        DB::table('users')->where('id', $owner['user']->id)->update(['password' => 'WithdrawnSyntheticCredential']);
                    } else {
                        config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
                        $other = DB::connection($name);
                        $pdo = $other->getPdo();
                        $other->setPdo(function () use ($pdo, &$callbacks) {
                            $callbacks++;
                            config(['production-free-grant-identity.enabled' => false]);

                            return $pdo;
                        });
                    }
                    $identity->provePrimary($principal, $owner['user'], $rows, $current);
                });
                $this->fail('Withdrawn identity/purpose cannot commit a new free mutation.');
            } catch (FreeGrantException) {
                $this->assertSame(0, DB::table('production_free_fixture')->count());
                $this->assertSame(0, $callbacks);
                $this->assertSame($owner['user']->getAuthPassword(), $owner['user']->fresh()->getAuthPassword());
            }
        } finally {
            DB::purge($name);
            DB::unprepared('DROP TABLE production_free_fixture');
        }
    }

    public function test_old_test_binding_foreign_origin_and_purpose_or_provenance_relabels_are_refused(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $this->enable();
        $identity = new ProductionFreeGrantIdentity;
        $principal = $identity->principal($owner['user']);
        $binding = $identity->durableBinding($principal);
        $invalid = [['family' => 'test_customer_account_v1', 'user_id' => $principal->userId, 'account_id' => $principal->accountId,
            'provenance' => 'synthetic-local-account', 'legal_identity_verified' => false]];
        foreach (['purpose', 'provenance', 'origin_id', 'verification_observation_hash'] as $field) {
            $wrong = $binding;
            if (in_array($field, ['purpose', 'provenance'], true)) {
                $wrong[$field] = 'old-test-family';
            } else {
                $wrong['buyer_binding'][$field] = str_repeat('c', 64);
            }
            $invalid[] = $wrong;
        }
        foreach ($invalid as $wrong) {
            try {
                DB::transaction(fn () => $identity->lockOriginal($principal, $owner['user'], $wrong, new FreeGrantRows));
                $this->fail('An old, foreign or changed identity binding cannot be adopted.');
            } catch (FreeGrantException $error) {
                $this->assertSame(403, $error->status);
            }
        }
    }

    public function test_default_off_missing_real_marker_and_old_principal_are_refused(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $identity = new ProductionFreeGrantIdentity;
        try {
            $identity->principal($owner['user']);
            $this->fail('The purpose must default off.');
        } catch (FreeGrantException $error) {
            $this->assertSame(404, $error->status);
        }
        $this->enable();
        $request = $this->request($owner);
        $request->session()->forget('_production_customer_identity');
        try {
            (new ProductionFreeGrantHttpIdentity)->forRequest($request);
            $this->fail('A cached guard user is insufficient without the actual T23 marker.');
        } catch (FreeGrantException $error) {
            $this->assertSame(403, $error->status);
        }
        $old = CustomerFixtures::account();
        foreach ([new \stdClass, (object) $owner['binding'], $old['principal']] as $untyped) {
            try {
                $identity->durableBinding($untyped);
                $this->fail('No public evidence object can become a typed principal.');
            } catch (FreeGrantException $error) {
                $this->assertSame(403, $error->status);
            }
        }
        config(['free-grants.test_enabled' => true]);
        try {
            $identity->durableBinding($owner['principal']);
            $this->fail('The old test family cannot be activated alongside the distinct purpose adapter.');
        } catch (FreeGrantException $error) {
            $this->assertSame(404, $error->status);
        }
    }

    private function enable(): void
    {
        config(['free-grants.operative_enabled' => true, 'free-grants.test_enabled' => false,
            'production-free-grant-identity.enabled' => true, 'production-free-grant-identity.provenance' => 'synthetic_rehearsal',
            'production-free-grant-identity.version' => ProductionFreeGrantIdentityPolicy::VERSION,
            'production-free-grant-identity.purpose' => ProductionFreeGrantIdentityPolicy::PURPOSE]);
    }

    private function request(array $owner): Request
    {
        Auth::guard('customer')->setUser($owner['user']);
        $request = Request::create('/free-grants');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $owner['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn () => $owner['user']);

        return $request;
    }
}
