<?php

namespace Tests\Feature;

use App\Domain\Customers\CustomerAccess;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\CustomerIdentityFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

class CustomerIdentityHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
    }

    public function test_real_enrollment_completion_and_recovery_leave_guards_separate_and_old_customer_session_unusable(): void
    {
        $staff = User::factory()->create(['is_admin' => true]);
        $this->actingAs($staff, 'web');
        $request = ['purpose' => 'enroll', 'email' => 'new@example.test', 'requestKey' => (string) Str::uuid()];
        $this->postJson('/account/identity/request', $request)->assertStatus(202)->assertExactJson(['accepted' => true])->assertHeader('Cache-Control', 'no-store, private');
        $body = $this->captured();
        $this->get('/account/access')->assertOk()->assertDontSee($body['proof'], false)->assertDontSee('new@example.test', false);
        $this->postJson('/account/identity/complete', $body)->assertOk()->assertExactJson(['completed' => true, 'next' => '/account/sign-in']);
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertSame($staff->id, Auth::guard('web')->id());
        $this->postJson('/account/sign-in', ['email' => 'NEW@EXAMPLE.TEST', 'password' => F::PASSWORD])->assertOk();
        $user = User::where('email', 'new@example.test')->sole();
        $account = app(CustomerAccess::class)->principal($user);
        $order = CustomerFixtures::prepared($user);
        $before = $order->fresh()->getAttributes();
        $this->getJson('/orders/history')->assertOk()->assertJsonPath('history.orders.0.id', $order->public_id);
        $this->postJson('/account/identity/request', ['purpose' => 'recover', 'email' => 'new@example.test', 'requestKey' => (string) Str::uuid()])->assertStatus(202);
        $recovery = $this->captured('recover');
        $recovery['password'] = F::REPLACEMENT;
        $this->postJson('/account/identity/complete', $recovery)->assertOk();
        $this->getJson('/orders/history')->assertForbidden();
        $this->assertSame($staff->id, Auth::guard('web')->id());
        $this->flushSession();
        Auth::forgetGuards();
        $this->postJson('/account/sign-in', ['email' => 'new@example.test', 'password' => F::REPLACEMENT])->assertOk();
        $this->getJson('/orders/history')->assertOk()->assertJsonPath('history.orders.0.id', $order->public_id);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertSame($account->ownerKey, app(CustomerAccess::class)->principal($user->fresh())->ownerKey);
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_unknown_existing_staff_withdrawn_and_case_variants_share_exact_request_response(): void
    {
        $staff = User::factory()->create(['email' => 'staff@example.test', 'is_admin' => true]);
        $customer = CustomerFixtures::account(['email' => 'customer@example.test']);
        CustomerFixtures::withdraw($customer);
        $this->withoutMiddleware(ThrottleRequests::class);
        foreach (['absent@example.test', $staff->email, strtoupper($customer['user']->email)] as $email) {
            foreach (['enroll', 'recover'] as $purpose) {
                $response = $this->postJson('/account/identity/request', ['purpose' => $purpose, 'email' => $email, 'requestKey' => (string) Str::uuid()])
                    ->assertStatus(202)->assertExactJson(['accepted' => true])->assertHeader('Cache-Control', 'no-store, private');
                $response->assertDontSee($email, false);
            }
        }
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('customer_accounts', 1);
    }

    public function test_feature_and_transport_fail_closed_in_production_and_never_send_or_log_a_proof(): void
    {
        foreach ([['customer.test_identity_enabled' => false], ['customer.identity_transport' => 'smtp'], ['customer.identity_transport' => 'log']] as $configuration) {
            F::configure();
            config($configuration);
            $this->get('/account/create')->assertNotFound();
            $this->postJson('/account/identity/request', ['purpose' => 'enroll', 'email' => 'new@example.test', 'requestKey' => (string) Str::uuid()])->assertNotFound();
        }
        F::configure();
        $this->app->detectEnvironment(fn () => 'production');
        try {
            $this->get('/account/recover')->assertNotFound();
            $this->assertDatabaseCount('customer_identity_challenges', 0);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_collation_only_legacy_address_matches_keep_the_generic_request_envelope(): void
    {
        // MySQL's configured collation equates these addresses; SQLite does not. Neither may disclose the stored row.
        $legacy = User::factory()->create(['email' => 'josé@example.test', 'is_admin' => true]);
        foreach (['enroll', 'recover'] as $purpose) {
            $this->postJson('/account/identity/request', ['purpose' => $purpose, 'email' => 'jose@example.test', 'requestKey' => (string) Str::uuid()])
                ->assertStatus(202)->assertExactJson(['accepted' => true])->assertDontSee($legacy->email, false);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customer_accounts', 0);
    }

    public function test_csrf_is_required_and_failure_never_flashes_proof_password_or_name(): void
    {
        $body = F::request();
        $csrf = Str::random(40);
        $this->withSession(['_token' => $csrf]);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $this->postJson('/account/identity/complete', $body)->assertStatus(419)->assertDontSee($body['proof'], false)->assertDontSee(F::PASSWORD, false);
        $this->assertDatabaseCount('users', 0);
        $this->assertFalse(session()->has('_old_input'));
        $this->postJson('/account/identity/complete', $body, ['X-CSRF-TOKEN' => $csrf])->assertOk();
    }

    #[DataProvider('badBodies')]
    public function test_private_boundary_rejects_ambiguous_or_oversized_request_without_echo(string $raw, string $suffix, array $headers): void
    {
        $response = $this->call('POST', '/account/identity/request'.$suffix, [], [], [], ['CONTENT_TYPE' => 'application/json', ...$headers], $raw);
        $this->assertContains($response->status(), [403, 413, 422]);
        $response->assertHeader('Cache-Control', 'no-store, private')->assertDontSee('private@example.test', false);
        $this->assertDatabaseCount('customer_identity_challenges', 0);
        $this->assertFalse(session()->has('_old_input'));
    }

    public static function badBodies(): array
    {
        return [
            ['{"purpose":"enroll","email":"private@example.test","email":"other@example.test","requestKey":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee"}', '', []],
            ['{"purpose":"enroll","email":"private@example.test","requestKey":"aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee","next":"https://bad.example"}', '', []],
            [str_repeat('x', 4097), '', []], ['{}', '?proof=private', []], ['{}', '', ['HTTP_ORIGIN' => 'https://foreign.example']],
        ];
    }

    private function captured(string $purpose = 'enroll'): array
    {
        $message = collect(Storage::disk('local')->files('customer-identity-capture'))
            ->map(fn ($file) => json_decode(Storage::disk('local')->get($file), true))->firstWhere('purpose', $purpose);
        [$kind, $id, $proof] = explode('.', parse_url($message['url'], PHP_URL_FRAGMENT));

        return ['purpose' => $kind, 'id' => $id, 'proof' => $proof, 'name' => $purpose === 'enroll' ? 'Synthetic customer' : '', 'password' => F::PASSWORD, 'requestKey' => (string) Str::uuid()];
    }
}
