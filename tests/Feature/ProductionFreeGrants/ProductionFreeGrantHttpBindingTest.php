<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantHttpBinding;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Restored from Codex's ff0 capsule (`codex/production-free-http-parent-guard-20261007`,
 * `tests/Feature/ProductionFreeIdentity/ProductionFreeHttpBindingTest.php` at ff0f7a85) and recomposed on the canonical
 * family 256 seams: `ProductionFreeGrantRequestIdentity` / `ProductionFreeGrantHttpBinding`, the `production-free-grants`
 * policy and `ProductionFreeGrantException` (`identity_refused`). The withdrawal set is ff0's, with the purpose withdrawal
 * pointed at the canonical configuration.
 */
final class ProductionFreeGrantHttpBindingTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_actual_smtp_current_owner_has_sealed_request_binding_without_public_or_cached_remint(): void
    {
        $owner = $this->customer();
        $request = $this->request($owner);
        $binding = ProductionFreeGrantHttpBinding::forRequest($request);
        $this->assertSame($owner['user'], $binding->actor());
        $this->assertSame($owner['binding'], $binding->principal()->durableBinding());
        $binding->proveCurrent();
        $identity = ProductionFreeGrantRequestIdentity::from($request);
        $this->assertSame($owner['user'], $identity->actor);
        $this->assertSame($owner['binding'], $identity->principal->durableBinding());
        $identity->proveCurrent();
        ($identity->authority())();
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

    public function test_initial_request_resolver_cannot_withdraw_the_policy_before_binding_capture(): void
    {
        $owner = $this->customer();
        $request = $this->request($owner);
        $request->setUserResolver(function () use ($owner) {
            config(['production-free-grants.enabled' => false]);

            return $owner['user'];
        });
        try {
            ProductionFreeGrantRequestIdentity::from($request);
            $this->fail('The callback stage must finish before the original policy is captured.');
        } catch (ProductionFreeGrantException $error) {
            // ff0 expected its policy's 404 here; the canonical policy's equivalent refusal is `disabled`.
            $this->assertSame('disabled', $error->reason);
        }
    }

    #[DataProvider('withdrawals')]
    public function test_actual_source_commit_withdrawal_cannot_return_http_authority(string $mode): void
    {
        $owner = $this->customer();
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
            ProductionFreeGrantRequestIdentity::from($request);
        } catch (ProductionFreeGrantException $error) {
            $refused = $error->reason === 'identity_refused';
        }
        $this->assertGreaterThanOrEqual(1, $commits);
        $this->assertTrue($refused, $mode.' cannot retain the old HTTP authority after callbacks.');
        $this->assertSame(0, $resolverCalls, 'The final check cannot invoke a replaced user resolver.');
        $this->assertEquals($before, DB::table('production_identity_origins')->get()->toArray());
    }

    #[DataProvider('withdrawals')]
    public function test_retained_same_request_binding_refuses_later_consumer_withdrawal_without_callbacks(string $mode): void
    {
        $owner = $this->customer();
        $request = $this->request($owner);
        $identity = ProductionFreeGrantRequestIdentity::from($request);
        $calls = 0;
        $this->withdraw($mode, $request, $owner, $calls);
        try {
            $identity->proveCurrent();
            $this->fail('The consumer must preserve the same original HTTP binding.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('identity_refused', $error->reason);
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
            'key' => config(['app.key' => 'base64:'.base64_encode(str_repeat('B', 32))]),
            'purpose' => config(['production-free-grants.purpose' => 'foreign-purpose']),
            'actor_attributes' => $owner['user']->setAttribute('name', 'Changed cached actor'),
            'login_marker' => $request->session()->put(Auth::guard('customer')->getName(), 999),
        };
    }

    private function request(array $owner): Request
    {
        Auth::guard('customer')->setUser($owner['user']);
        $request = Request::create('/production-free-grants/library');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $owner['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn () => $owner['user']);

        return $request;
    }
}
