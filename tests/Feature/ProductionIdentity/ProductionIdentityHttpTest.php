<?php

namespace Tests\Feature\ProductionIdentity;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

class ProductionIdentityHttpTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->identitySetup();
        $this->withoutVite();
        Route::middleware('web')->group(base_path('routes/production-customer-identity.php'));
    }

    public function test_http_mailbox_completion_and_new_session_do_not_change_staff_guard_and_recovery_invalidates_old_marker(): void
    {
        $staff = User::factory()->create(['is_admin' => true]);
        $this->actingAs($staff, 'web');
        $this->postJson('/customer/identity/request', ['purpose' => 'enroll', 'email' => 'buyer@example.test', 'requestKey' => str_repeat('a', 64)])->assertStatus(202)->assertExactJson(['accepted' => true])->assertHeader('Cache-Control', 'no-store, private');
        $challenge = (array) DB::table('production_identity_challenges')->first();
        [$id, $proof] = $this->proofFor($challenge);
        $this->get('/customer/access')->assertOk()->assertDontSee($proof, false)->assertDontSee('buyer@example.test', false);
        $this->postJson('/customer/identity/complete', ['id' => $id, 'proof' => $proof, 'password' => 'MailboxPassword123', 'name' => 'Buyer', 'requestKey' => str_repeat('b', 64)])->assertOk()->assertExactJson(['completed' => true, 'next' => '/customer/sign-in']);
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertSame($staff->id, Auth::guard('web')->id());
        $this->postJson('/customer/sign-in', ['email' => 'BUYER@EXAMPLE.TEST', 'password' => 'MailboxPassword123'])->assertOk()->assertExactJson(['authenticated' => true, 'next' => '/customer']);
        $this->get('/customer')->assertOk()->assertDontSee($proof, false);
        $marker = session('_production_customer_identity');
        $this->assertSame(['binding_digest'], array_keys($marker));
        $recovery = $this->requestIdentity('recover');
        $this->completeIdentity($recovery, 'RecoveredPassword456');
        $this->get('/customer')->assertRedirect('/customer/sign-in');
        $this->assertSame($staff->id, Auth::guard('web')->id());
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'RecoveredPassword456'])->assertOk();
        $this->assertNotSame($marker, session('_production_customer_identity'));
        $this->get('/customer')->assertOk();
        $this->call('POST', '/customer/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk();
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertSame($staff->id, Auth::guard('web')->id());
    }

    public function test_login_event_credential_change_cannot_stamp_later_password_as_authenticated(): void
    {
        $this->completeIdentity($this->requestIdentity());
        Event::listen(Login::class, function ($event): void {
            if ($event->guard === 'customer') {
                DB::table('users')->where('id', $event->user->id)->update(['password' => 'withdrawn-after-login']);
            }
        });
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertStatus(422);
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertFalse(session()->has('_production_customer_identity'));
    }

    public function test_legacy_cached_customer_guard_without_new_session_marker_does_not_mint_access(): void
    {
        $result = $this->completeIdentity($this->requestIdentity());
        $this->actingAs(User::findOrFail($result['user_id']), 'customer');
        $this->get('/customer')->assertRedirect('/customer/sign-in');
    }

    #[DataProvider('privateRequests')]
    public function test_private_boundary_rejects_ambiguous_body_query_origin_or_size_without_echo(string $raw, string $suffix, array $headers, int $status): void
    {
        $this->call('POST', '/customer/identity/request'.$suffix, [], [], [], ['CONTENT_TYPE' => 'application/json', ...$headers], $raw)->assertStatus($status)->assertDontSee('private-proof', false)->assertDontSee('private@example.test', false);
        $this->assertSame(0, DB::table('production_identity_challenges')->count());
        $this->assertFalse(session()->has('_old_input'));
    }

    public static function privateRequests(): array
    {
        return [['{}', '?proof=private-proof', [], 422], ['{}', '', ['HTTP_ORIGIN' => 'https://foreign.example'], 403], [str_repeat('x', 4097), '', [], 413],
            ['{"purpose":"enroll","email":"private@example.test","email":"other@example.test","requestKey":"'.str_repeat('a', 64).'"}', '', [], 422]];
    }

    public function test_real_csrf_rejection_never_flashes_proof_or_password(): void
    {
        $challenge = $this->requestIdentity();
        [$id, $proof] = $this->proofFor($challenge);
        $csrf = str_repeat('c', 40);
        $this->withSession(['_token' => $csrf]);
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $body = ['id' => $id, 'proof' => $proof, 'password' => 'MailboxPassword123', 'name' => 'Buyer', 'requestKey' => str_repeat('b', 64)];
        $this->postJson('/customer/identity/complete', $body)->assertStatus(419)->assertDontSee($proof, false)->assertDontSee('MailboxPassword123', false);
        $this->assertFalse(session()->has('_old_input'));
        $this->assertSame(0, DB::table('users')->count());
        $this->postJson('/customer/identity/complete', $body, ['X-CSRF-TOKEN' => $csrf])->assertOk();
    }
}
