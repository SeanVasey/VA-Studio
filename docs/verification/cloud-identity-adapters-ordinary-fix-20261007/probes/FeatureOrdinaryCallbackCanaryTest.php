<?php

namespace Tests\IndependentIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeaturePolicy;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class FeatureOrdinaryCallbackCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_lazy_secondary_connection_cannot_withdraw_feature_after_normal_transaction_fence(): void
    {
        $this->identitySetup();
        config(['production-account-features.enabled' => true, 'production-account-features.provenance' => IdentityPolicy::REHEARSAL,
            'production-account-features.versions' => ProductionAccountFeaturePolicy::VERSIONS]);
        $verified = $this->enrollThroughLocalSmtp();
        $request = Request::create('/customer/library');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $verified['principal']->sessionBindingDigest()]);
        Auth::guard('customer')->setUser($verified['user']);
        $request->setUserResolver(fn () => $verified['user']);
        $access = new ProductionAccountFeatureAccess;
        $owner = $access->forRequest($request, 'listening_library');
        $pdo = DB::connection()->getPdo();
        $reader = new CurrentRows($pdo, DB::getDriverName());
        $proof = DB::transaction(function () use ($access, $owner, $reader): array {
            $raw = $access->lock($owner, $reader);
            $access->proveCurrent($owner, $reader, $raw);

            return $raw;
        });
        $name = 'independent_identity_frame_lazy';
        config(['database.connections.'.$name => config('database.connections.'.DB::getDefaultConnection())]);
        $secondary = DB::connection($name);
        $secondaryPdo = $secondary->getPdo();
        $callbacks = 0;
        // Real public Laravel lazy-PDO resolver installed by extensible module work before the final fence.
        $secondary->setPdo(function () use ($secondaryPdo, &$callbacks) {
            $callbacks++;
            config(['production-account-features.enabled' => false]);

            return $secondaryPdo;
        });
        DB::unprepared('CREATE TABLE ordinary_feature_fixture (id integer PRIMARY KEY)');
        $released = false;
        $refused = false;
        try {
            try {
                DB::transaction(function () use ($access, $owner, $reader, $proof): void {
                    DB::table('ordinary_feature_fixture')->insert(['id' => 1]);
                    $access->proveCurrent($owner, $reader, $proof);
                });
                $released = true;
            } catch (IdentityException) {
                $refused = true;
            }
        } finally {
            DB::purge($name);
        }
        file_put_contents(base_path('docs/verification/cloud-identity-adapters-independent-20261007/ordinary-feature-callback-snapshot.json'), json_encode([
            'source' => trim(shell_exec('git rev-parse HEAD')), 'callback_count' => $callbacks, 'ordinary_write_count' => DB::table('ordinary_feature_fixture')->count(),
            'feature_enabled_after_final_proof' => config('production-account-features.enabled'),
            'refused' => $refused, 'private_projection_released' => $released,
            'framework_transaction_depth' => DB::transactionLevel(), 'physical_transaction_active' => $pdo->inTransaction(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->assertFalse($released, 'A real lazy secondary connection callback withdrew feature authority after the policy check.');
        $this->assertTrue($refused);
    }
}
