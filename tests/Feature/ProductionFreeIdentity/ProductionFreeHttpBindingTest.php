<?php

namespace Tests\Feature\ProductionFreeIdentity;

use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\Production\ProductionFreeGrantHttpBinding;
use App\Domain\Grants\Free\Production\ProductionFreeGrantHttpIdentity;
use App\Domain\Grants\Free\Production\ProductionFreeGrantIdentityPolicy;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionFreeHttpBindingTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
        config(['free-grants.operative_enabled' => true, 'free-grants.test_enabled' => false,
            'production-free-grant-identity.enabled' => true, 'production-free-grant-identity.provenance' => 'synthetic_rehearsal',
            'production-free-grant-identity.version' => ProductionFreeGrantIdentityPolicy::VERSION,
            'production-free-grant-identity.purpose' => ProductionFreeGrantIdentityPolicy::PURPOSE]);
    }

    public function test_actual_smtp_current_owner_has_sealed_request_binding_without_public_or_cached_remint(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $request = $this->request($owner);
        $binding = (new ProductionFreeGrantHttpIdentity)->binding($request);
        $this->assertInstanceOf(ProductionFreeGrantHttpBinding::class, $binding);
        $this->assertSame($owner['user'], $binding->actor());
        $this->assertSame($owner['binding'], $binding->principal()->durableBinding());
        $binding->proveCurrent();
        [$principal, $actor] = (new ProductionFreeGrantHttpIdentity)->forRequest($request);
        $this->assertSame($owner['user'], $actor);
        $this->assertSame($owner['binding'], $principal->durableBinding());
        $this->assertSame(['captured' => true], $binding->__debugInfo());
        foreach (['serialize', 'json'] as $mode) {
            try {
                $mode === 'serialize' ? serialize($binding) : json_encode($binding, JSON_THROW_ON_ERROR);
                $this->fail('Private HTTP authority cannot be projected or serialized.');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public static function withdrawals(): array
    {
        return array_combine($modes = ['marker_forget', 'marker_replace', 'session_replace', 'session_id',
            'guard_forget', 'guard_actor', 'request_resolver', 'auth_resolver', 'key', 'purpose', 'actor_attributes', 'login_marker'],
            array_map(fn (string $mode): array => [$mode], $modes));
    }

    public function test_initial_request_resolver_cannot_withdraw_purpose_before_binding_capture(): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $request = $this->request($owner);
        $request->setUserResolver(function () use ($owner) {
            config(['production-free-grant-identity.enabled' => false]);

            return $owner['user'];
        });
        try {
            (new ProductionFreeGrantHttpIdentity)->forRequest($request);
            $this->fail('The callback stage must finish before the original policy is captured.');
        } catch (FreeGrantException $error) {
            $this->assertSame(404, $error->status);
        }
    }

    #[DataProvider('withdrawals')]
    public function test_actual_source_commit_withdrawal_cannot_return_http_authority(string $mode): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $request = $this->request($owner);
        $before = DB::table('production_identity_origins')->get()->toArray();
        $commits = $resolverCalls = 0;
        $this->app['events']->listen(TransactionCommitted::class, function () use ($request, $owner, $mode, &$commits, &$resolverCalls): void {
            if (++$commits === 1) {
                $this->withdraw($mode, $request, $owner, $resolverCalls);
            }
        });
        $refused = false;
        try {
            (new ProductionFreeGrantHttpIdentity)->forRequest($request);
        } catch (FreeGrantException $error) {
            $refused = $error->status === 403;
        }
        $this->assertGreaterThanOrEqual(1, $commits);
        $this->assertTrue($refused, $mode.' cannot retain the old HTTP authority after callbacks.');
        $this->assertSame(0, $resolverCalls, 'The final check cannot invoke a replaced user resolver.');
        $this->assertEquals($before, DB::table('production_identity_origins')->get()->toArray());
    }

    #[DataProvider('withdrawals')]
    public function test_retained_same_request_binding_refuses_later_consumer_withdrawal_without_callbacks(string $mode): void
    {
        $owner = $this->enrollThroughLocalSmtp();
        $request = $this->request($owner);
        $binding = (new ProductionFreeGrantHttpIdentity)->binding($request);
        $calls = 0;
        $this->withdraw($mode, $request, $owner, $calls);
        try {
            $binding->proveCurrent();
            $this->fail('The consumer must preserve the same original HTTP binding.');
        } catch (FreeGrantException $error) {
            $this->assertSame(403, $error->status);
            $this->assertSame(0, $calls);
        }
    }

    private function withdraw(string $mode, Request $request, array $owner, int &$calls): void
    {
        match ($mode) {
            'marker_forget' => $request->session()->forget('_production_customer_identity'),
            'marker_replace' => $request->session()->put('_production_customer_identity', ['binding_digest' => str_repeat('c', 64)]),
            'session_replace' => $request->setLaravelSession(new Store('foreign-synthetic', app('session.store')->getHandler())),
            'session_id' => $request->session()->setId(str_repeat('d', 40)),
            'guard_forget' => Auth::forgetGuards(),
            'guard_actor' => Auth::guard('customer')->setUser(clone $owner['user']),
            'request_resolver' => $request->setUserResolver(function () use ($owner, &$calls) {
                $calls++;

                return $owner['user'];
            }),
            'auth_resolver' => Auth::resolveUsersUsing(function () use ($owner, &$calls) {
                $calls++;

                return $owner['user'];
            }),
            'key' => config(['app.key' => 'base64:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB=']),
            'purpose' => config(['production-free-grant-identity.purpose' => 'foreign-purpose']),
            'actor_attributes' => $owner['user']->setAttribute('name', 'Changed cached actor'),
            'login_marker' => $request->session()->put(Auth::guard('customer')->getName(), 999),
        };
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
