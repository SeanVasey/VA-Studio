<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Assent is recorded against the session-bound principal, never one inferred from email or request input. */
final class ProductionFreeGrantRequestIdentityTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_session_marker_principal_and_customer_guard_actor_record_the_assent(): void
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $identity = ProductionFreeGrantRequestIdentity::from($this->request($owner['user'], $owner['principal']->sessionBindingDigest()));
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $identity->principal, $identity->actor);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $identity->principal, $identity->actor);
        $this->assertSame((int) $owner['user']->id, (int) DB::table('production_free_origins')->where('id', $origin['id'])->value('user_id'));
    }

    public function test_missing_forged_or_foreign_session_markers_are_refused(): void
    {
        $owner = $this->customer();
        $other = $this->customer('other@example.test');
        foreach ([
            $this->request($owner['user'], null),
            $this->request($owner['user'], str_repeat('a', 64)),
            $this->request($owner['user'], $other['principal']->sessionBindingDigest()),
            $this->request($this->staff(), $owner['principal']->sessionBindingDigest()),
        ] as $index => $request) {
            try {
                ProductionFreeGrantRequestIdentity::from($request);
                $this->fail('Refusal expected for case '.$index);
            } catch (ProductionFreeGrantException $error) {
                $this->assertSame('identity_refused', $error->reason);
            }
        }
    }

    private function request(User $user, ?string $digest): Request
    {
        Auth::guard('customer')->setUser($user);
        $request = Request::create('/customer/free-grants', 'POST', ['principal' => 'ignored', 'email' => 'other@example.test']);
        $request->setLaravelSession(app('session.store'));
        $request->session()->forget('_production_customer_identity');
        if ($digest !== null) {
            $request->session()->put('_production_customer_identity', ['binding_digest' => $digest]);
        }
        $request->setUserResolver(fn (?string $guard = null) => Auth::guard($guard ?? 'web')->user());

        return $request;
    }
}
