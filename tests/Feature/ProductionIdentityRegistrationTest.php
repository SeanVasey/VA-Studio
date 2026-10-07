<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionIdentityRegistrationTest extends TestCase
{
    use ProductionIdentityFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('I', 32))]);
        $this->identitySetup();
        $this->withoutVite();
    }

    public function test_registered_http_uses_actual_smtp_proof_and_recovery_preserves_owner_but_invalidates_old_session(): void
    {
        $this->postJson('/customer/identity/request', ['purpose' => 'enroll', 'email' => 'buyer@example.test', 'requestKey' => str_repeat('a', 64)])->assertStatus(202)->assertExactJson(['accepted' => true]);
        [$id, $proof] = $this->mailboxProof();
        $this->get('/customer/access')->assertOk()->assertDontSee($proof, false)->assertDontSee('buyer@example.test', false);
        $this->postJson('/customer/identity/complete', ['id' => $id, 'proof' => $proof, 'password' => 'MailboxPassword123', 'name' => 'Synthetic declared buyer', 'requestKey' => str_repeat('b', 64)])->assertOk();
        $before = (array) DB::table('customer_accounts')->first();
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertOk();
        $marker = session('_production_customer_identity');
        $this->assertSame(['binding_digest'], array_keys($marker));
        $this->private($this->get('/customer')->assertOk()->assertDontSee($proof, false));
        $this->postJson('/customer/identity/request', ['purpose' => 'recover', 'email' => 'buyer@example.test', 'requestKey' => str_repeat('c', 64)])->assertStatus(202);
        [$recoveryId, $recoveryProof] = $this->mailboxProof();
        $this->postJson('/customer/identity/complete', ['id' => $recoveryId, 'proof' => $recoveryProof, 'password' => 'RecoveredPassword456', 'name' => 'Synthetic declared buyer', 'requestKey' => str_repeat('d', 64)])->assertOk();
        $this->get('/customer')->assertRedirect('/customer/sign-in');
        $after = (array) DB::table('customer_accounts')->first();
        $this->assertSame($before['id'], $after['id']);
        $this->assertSame($before['owner_key'], $after['owner_key']);
        $this->assertSame($before, $after);
        $this->assertDatabaseCount('production_identity_origins', 1);
        $this->assertDatabaseCount('production_identity_verifications', 2);
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'RecoveredPassword456'])->assertOk();
        $this->assertNotSame($marker, session('_production_customer_identity'));
        $this->get('/customer')->assertOk();
        $this->call('POST', '/customer/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertOk();
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertFalse(session()->has('_production_customer_identity'));
    }

    public function test_real_csrf_fails_with_private_generic_response_before_any_writer(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $token = str_repeat('t', 40);
        $this->withSession(['_token' => $token]);
        $body = ['purpose' => 'enroll', 'email' => 'buyer@example.test', 'requestKey' => str_repeat('a', 64)];
        $this->private($this->postJson('/customer/identity/request', $body)->assertStatus(419)->assertDontSee('buyer@example.test', false));
        $this->assertDatabaseCount('production_identity_challenges', 0);
        $this->assertFalse(session()->has('_old_input'));
        $this->postJson('/customer/identity/request', $body, ['X-CSRF-TOKEN' => $token])->assertStatus(202);
        $this->assertDatabaseCount('production_identity_challenges', 1);
    }

    #[DataProvider('unavailableRequests')]
    public function test_global_private_boundary_covers_unregistered_paths_methods_and_disabled_identity(string $method, string $path, int $status): void
    {
        config(['production-customer-identity.enabled' => false, 'app.debug' => true]);
        $response = $this->call($method, $path, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], '{}');
        $this->private($response->assertStatus($status)->assertDontSee('trace', false)->assertDontSee('exception', false));
        $this->assertDatabaseCount('production_identity_challenges', 0);
    }

    public static function unavailableRequests(): array
    {
        return [['GET', '/customer/create', 422], ['GET', '/customer/missing', 404], ['PUT', '/customer/sign-in', 405], ['POST', '/customer/identity/request', 404]];
    }

    public function test_precontroller_exception_is_sanitized_and_logged_without_private_message_or_trace(): void
    {
        Route::middleware('web')->get('/customer/registration-failure', fn () => throw new \RuntimeException('Synthetic private proof and recipient must stay out of logs'));
        config(['app.debug' => true]);
        Log::shouldReceive('error')->once()->with('Customer identity request failed.', ['exception_class' => \RuntimeException::class]);
        $this->private($this->getJson('/customer/registration-failure')->assertStatus(503)->assertDontSee('Synthetic private', false)->assertDontSee('trace', false));
    }

    private function mailboxProof(): array
    {
        $message = $this->smtp('accept', (int) DB::table('production_identity_notices')->orderByDesc('id')->value('id'));
        $this->assertSame(1, preg_match('~http://localhost/customer/access#(?:enroll|recover)\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $message['data'] ?? '', $match));

        return [$match[1], $match[2]];
    }

    private function private($response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
