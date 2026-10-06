<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\PriceQuote;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class CustomerSessionHttpTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
        OrderFixtures::configure();
    }

    public function test_real_customer_login_preserves_account_history_across_fresh_sessions_without_staff_login(): void
    {
        $f = F::account();
        $this->login($f);
        $this->assertFalse(Auth::guard('web')->check());
        $selection = InventoryFixtures::selection();
        $quote = $this->postJson('/quotes', ['items' => $selection['items']], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('quote.id');
        $this->call('POST', '/quotes/'.$quote.'/pricing', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk();
        $review = $this->getJson('/quotes/'.$quote.'/order-review')->assertOk()->json('review');
        $id = $this->postJson('/orders', ['quoteId' => $quote, 'reviewHash' => $review['reviewHash'], 'buyer' => OrderFixtures::buyer(), 'accepted' => true], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('order.id');
        $this->assertSame($f['principal']->ownerKey, DB::table('orders')->where('public_id', $id)->value('owner_key'));
        $this->flushSession();
        Auth::forgetGuards();
        $this->getJson('/orders/'.$id.'/status')->assertNotFound();
        $this->login($f);
        $this->getJson('/orders/history')->assertOk()->assertJsonPath('history.orders.0.id', $id)->assertDontSee($f['principal']->ownerKey, false);
        $this->getJson('/orders/'.$id.'/status')->assertOk();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_unknown_wrong_password_staff_unverified_and_withdrawn_accounts_have_same_generic_failure(): void
    {
        $f = F::account();
        $responses = [];
        $responses[] = $this->postJson('/account/sign-in', ['email' => 'absent@example.test', 'password' => F::PASSWORD])->assertStatus(422)->json();
        $responses[] = $this->postJson('/account/sign-in', ['email' => $f['user']->email, 'password' => 'wrong'])->assertStatus(422)->json();
        F::withdraw($f);
        $responses[] = $this->postJson('/account/sign-in', ['email' => $f['user']->email, 'password' => F::PASSWORD])->assertStatus(422)->json();
        foreach ([['is_admin' => true], ['email_verified_at' => null]] as $attributes) {
            $user = User::factory()->create(['password' => F::PASSWORD, ...$attributes]);
            $responses[] = $this->postJson('/account/sign-in', ['email' => $user->email, 'password' => F::PASSWORD])->assertStatus(422)->json();
        }
        foreach ($responses as $response) {
            $this->assertSame($responses[0], $response);
        }
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_withdrawal_invalidates_an_already_authenticated_session_and_logout_remains_available(): void
    {
        $f = F::account();
        $this->login($f);
        F::withdraw($f);
        $this->getJson('/orders/history')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/account')->assertRedirect('/account/sign-in');
        $this->call('POST', '/account/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertOk()->assertJsonPath('authenticated', false);
        $this->getJson('/orders/history')->assertOk()->assertJsonCount(0, 'history.orders');
    }

    public function test_matching_buyer_email_never_links_a_guest_order(): void
    {
        $selection = InventoryFixtures::selection();
        $owner = str_repeat('a', 64);
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $selection['items']);
        app(PriceQuote::class)->create($quote->public_id, $owner);
        $order = app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), OrderFixtures::request($quote, $owner));
        $f = F::account(['email' => OrderFixtures::buyer()['email']]);
        $this->login($f);
        $this->getJson('/orders/history')->assertOk()->assertJsonCount(0, 'history.orders');
        $this->getJson('/orders/'.$order->public_id.'/status')->assertNotFound();
        $this->assertSame($owner, $order->fresh()->owner_key);
    }

    public function test_customer_auth_request_rejects_extra_duplicate_and_nested_keys_without_flashing_credentials(): void
    {
        $f = F::account();
        foreach (['{"email":"a@example.test","email":"b@example.test","password":"secret"}',
            '{"email":"a@example.test","password":"secret","next":"https://example.test"}',
            '{"email":"a@example.test","password":{"value":"secret"}}'] as $body) {
            $this->call('POST', '/account/sign-in', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
                ->assertStatus(422)->assertDontSee('secret')->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertFalse(session()->has('_old_input'));
    }

    public function test_password_reset_during_guard_login_never_receives_a_replacement_credential_stamp(): void
    {
        $f = F::account();
        Event::listen(Login::class, function (Login $event) use ($f): void {
            if ($event->guard === 'customer') {
                User::whereKey($f['user']->id)->update(['password' => Hash::make('Replacement-test-password')]);
            }
        });
        $this->postJson('/account/sign-in', ['email' => $f['user']->email, 'password' => F::PASSWORD])->assertStatus(422);
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertFalse(session()->has('_customer_access'));
        $this->assertTrue(Hash::check('Replacement-test-password', $f['user']->fresh()->password));
    }

    public function test_real_csrf_session_rotation_and_staff_separation_survive_signin_and_signout(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $f = F::account();
        $staff = User::factory()->create(['is_admin' => true]);
        $this->actingAs($staff, 'web');
        $this->get('/account/sign-in')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $id = session()->getId();
        $csrf = session()->token();
        config(['app.debug' => true]);
        $body = ['email' => $f['user']->email, 'password' => F::PASSWORD];
        $this->postJson('/account/sign-in', $body, ['X-CSRF-TOKEN' => 'bad-private-token'])->assertStatus(419)->assertDontSee(F::PASSWORD)->assertDontSee('bad-private-token');
        $this->assertFalse(Auth::guard('customer')->check());
        $this->postJson('/account/sign-in', $body, ['X-CSRF-TOKEN' => $csrf])->assertOk();
        $this->assertNotSame($id, session()->getId());
        $this->assertNotSame($csrf, session()->token());
        $this->assertSame($staff->id, Auth::guard('web')->id());
        $this->assertSame($f['user']->id, Auth::guard('customer')->id());
        $newId = session()->getId();
        $newCsrf = session()->token();
        $this->call('POST', '/account/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $csrf], '{}')->assertStatus(419);
        $this->assertTrue(Auth::guard('customer')->check());
        $this->call('POST', '/account/sign-out', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $newCsrf], '{}')->assertOk();
        $this->assertNotSame($newId, session()->getId());
        $this->assertNotSame($newCsrf, session()->token());
        $this->assertFalse(Auth::guard('customer')->check());
        $this->assertSame($staff->id, Auth::guard('web')->id());
    }

    public function test_request_bounds_origin_and_throttle_fail_privately_without_authenticating(): void
    {
        $f = F::account();
        $body = ['email' => $f['user']->email, 'password' => F::PASSWORD];
        $this->postJson('/account/sign-in', $body, ['Origin' => 'https://foreign.example'])->assertForbidden();
        $this->postJson('/account/sign-in?password=private', $body)->assertStatus(422)->assertDontSee('private');
        $this->call('POST', '/account/sign-in', [], [], [], ['CONTENT_TYPE' => 'application/json'], str_repeat('x', 4097))->assertStatus(413);
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/account/sign-in', ['email' => 'unknown@example.test', 'password' => 'wrong'])->assertStatus(422);
        }
        $this->postJson('/account/sign-in', $body)->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertFalse(Auth::guard('customer')->check());
    }

    public function test_credential_rehash_is_completed_before_retaining_stamp_and_reset_invalidates_that_session(): void
    {
        $f = F::account();
        User::whereKey($f['user']->id)->update(['password' => password_hash(F::PASSWORD, PASSWORD_BCRYPT, ['cost' => 5])]);
        $before = $f['user']->fresh()->password;
        $this->login($f);
        $after = $f['user']->fresh()->password;
        $this->assertNotSame($before, $after);
        $this->assertFalse(Hash::needsRehash($after));
        $this->getJson('/orders/history')->assertOk();
        User::whereKey($f['user']->id)->update(['password' => Hash::make('A-new-synthetic-credential')]);
        $this->getJson('/orders/history')->assertForbidden()->assertHeader('Cache-Control', 'no-store, private');
    }

    private function login(array $fixture): void
    {
        $this->postJson('/account/sign-in', ['email' => $fixture['user']->email, 'password' => F::PASSWORD])->assertOk()
            ->assertExactJson(['authenticated' => true, 'next' => '/account'])->assertHeader('Cache-Control', 'no-store, private');
    }
}
