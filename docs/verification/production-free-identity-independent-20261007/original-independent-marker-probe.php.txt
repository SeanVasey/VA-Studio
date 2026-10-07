<?php

namespace Tests\Feature\ProductionFreeIdentity;

use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\Production\ProductionFreeGrantHttpIdentity;
use App\Domain\Grants\Free\Production\ProductionFreeGrantIdentityPolicy;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

/** Actual mailbox principal and session, revoked by a real source TransactionCommitted callback. */
final class ProductionFreeHttpCurrentMarkerIndependentTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_actual_current_source_commit_cannot_revoke_marker_and_return_http_authority(): void
    {
        $this->identitySetup();
        $owner = $this->enrollThroughLocalSmtp();
        config(['free-grants.operative_enabled' => true, 'free-grants.test_enabled' => false,
            'production-free-grant-identity.enabled' => true, 'production-free-grant-identity.provenance' => 'synthetic_rehearsal',
            'production-free-grant-identity.version' => ProductionFreeGrantIdentityPolicy::VERSION,
            'production-free-grant-identity.purpose' => ProductionFreeGrantIdentityPolicy::PURPOSE]);
        Auth::guard('customer')->setUser($owner['user']);
        $request = Request::create('/free-grants');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $owner['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn () => $owner['user']);
        $before = DB::table('production_identity_origins')->get()->toArray();
        $commits = 0;
        $this->app['events']->listen(TransactionCommitted::class, function () use ($request, &$commits): void {
            if (++$commits === 1) {
                $request->session()->forget('_production_customer_identity');
            }
        });
        $refused = false;
        try {
            (new ProductionFreeGrantHttpIdentity)->forRequest($request);
        } catch (FreeGrantException $error) {
            $refused = $error->status === 403;
        }
        $this->assertGreaterThanOrEqual(1, $commits);
        $this->assertFalse($request->session()->has('_production_customer_identity'));
        $this->assertEquals($before, DB::table('production_identity_origins')->get()->toArray());
        $this->assertTrue($refused, 'Actual HTTP identity must refuse the withdrawn original session marker after current-source callbacks.');
    }
}
