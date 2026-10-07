<?php

namespace Tests\Feature;

use App\Domain\Memberships\MembershipPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures;
use Tests\TestCase;

class MembershipCustomerLibraryCapabilityTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        MembershipFixtures::configure();
    }

    public function test_library_exposes_only_current_synthetic_capability_and_a_transient_nonidentity_scope(): void
    {
        $f = CustomerFixtures::account();
        $this->login($f);
        $first = $this->get('/account', ['X-Inertia' => 'true'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('props.testMembershipsEnabled', true)->json('props.membershipHistoryScope');
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $first);
        $second = $this->get('/account', ['X-Inertia' => 'true'])->assertOk()->json('props.membershipHistoryScope');
        $this->assertNotSame($first, $second, 'Scope is per-render invalidation, not a stable account identifier.');
        $this->assertNotSame($f['account']->owner_key, $first);
        $this->assertFalse(Auth::guard('web')->check());
        $this->assertDatabaseCount('membership_credit_buckets', 0);
    }

    public static function strictFlags(): array
    {
        return ['false' => [false], 'null' => [null], 'integer' => [1], 'string' => ['true']];
    }

    #[DataProvider('strictFlags')]
    public function test_disabled_or_nonboolean_flag_hides_membership_capability_and_scope(mixed $flag): void
    {
        $f = CustomerFixtures::account();
        $this->login($f);
        config(['memberships.test_mode_enabled' => $flag]);
        $this->get('/account', ['X-Inertia' => 'true'])->assertOk()
            ->assertJsonPath('props.testMembershipsEnabled', false)->assertJsonPath('props.membershipHistoryScope', null);
        $this->assertFalse(app(MembershipPolicy::class)->enabled());
        $this->expectException(AuthorizationException::class);
        app(MembershipPolicy::class)->requireEnabled();
    }

    public function test_production_always_refuses_membership_capability_even_if_flag_true(): void
    {
        config(['memberships.test_mode_enabled' => true]);
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->assertFalse(app(MembershipPolicy::class)->enabled());
            $this->expectException(AuthorizationException::class);
            app(MembershipPolicy::class)->requireEnabled();
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_no_scope_is_returned_to_guest_or_withdrawn_customer(): void
    {
        $this->get('/account')->assertRedirect('/account/sign-in');
        $f = CustomerFixtures::account();
        $this->login($f);
        CustomerFixtures::withdraw($f);
        $this->get('/account')->assertRedirect('/account/sign-in');
    }

    public function test_same_name_account_switch_receives_distinct_history_scope_with_fresh_authentication(): void
    {
        $first = CustomerFixtures::account();
        $second = CustomerFixtures::account();
        $second['user']->update(['name' => $first['user']->name]);
        $this->login($first);
        $a = $this->get('/account', ['X-Inertia' => 'true'])->assertOk()->json('props.membershipHistoryScope');
        $this->call('POST', '/account/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        Auth::forgetGuards();
        $this->login($second);
        $b = $this->get('/account', ['X-Inertia' => 'true'])->assertOk()->json('props.membershipHistoryScope');
        $this->assertNotSame($a, $b);
    }

    private function login(array $f): void
    {
        $this->postJson('/account/sign-in', ['email' => $f['user']->email, 'password' => CustomerFixtures::PASSWORD])->assertOk();
    }
}
