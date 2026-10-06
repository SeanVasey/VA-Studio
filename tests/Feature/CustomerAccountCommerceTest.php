<?php

namespace Tests\Feature;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Delivery\PrepareTestDeliveryStream;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CheckoutFixtures;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

class CustomerAccountCommerceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_fresh_customer_session_reads_exact_original_contract_and_asset_with_explicit_audit_actor(): void
    {
        $f = F::account();
        $paid = F::ready($f['user']);
        $manifest = F::browserManifest($paid);
        $id = $paid['order']->public_id;
        $evidence = DeliveryFixtures::retained();
        $this->login($f);
        $this->getJson('/orders/history')->assertOk()->assertJsonPath('history.orders.0.id', $id);
        $this->flushSession();
        Auth::forgetGuards();
        $this->get('/orders/'.$id.'/delivery', ['Accept' => 'application/json'])->assertNotFound();
        $this->login($f);
        // Simultaneous staff authentication cannot override the explicit customer audit actor.
        $staff = User::factory()->create(['is_admin' => true]);
        $this->actingAs($staff, 'web');
        $delivery = $this->get('/orders/'.$id.'/delivery', ['Accept' => 'application/json'])->assertOk()->json('delivery');
        foreach ($delivery['items'] as $item) {
            $authorization = $this->postJson('/orders/'.$id.'/delivery/authorizations', ['grantId' => $item['grantId'], 'kind' => $item['kind']],
                ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('authorization');
            $body = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => session()->token()]);
            $response = $this->call('POST', '/orders/'.$id.'/delivery/download', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertOk();
            $bytes = $response->streamedContent();
            $expected = $manifest[$item['kind'] === 'contract' ? 'contract' : 'asset'];
            $this->assertSame($expected['sha256'], hash('sha256', $bytes));
            $this->assertSame($expected['sizeBytes'], strlen($bytes));
            $response->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->assertSame([$f['user']->id], DB::table('audit_events')->whereIn('action', ['commerce.delivery.authorized', 'commerce.delivery.redeemed'])->distinct()->pluck('actor_id')->all());
        $this->assertSame($evidence, DeliveryFixtures::retained());
    }

    public function test_withdrawal_after_file_preparation_cannot_issue_authorization_and_closes_private_spool(): void
    {
        $f = F::account();
        $paid = F::ready($f['user']);
        $this->login($f);
        $streams = DeliveryFixtures::observingStreams();
        $streams->afterPrepare = fn () => F::withdraw($f);
        $this->app->instance(PrepareTestDeliveryStream::class, $streams);
        $this->postJson('/orders/'.$paid['order']->public_id.'/delivery/authorizations', ['grantId' => $paid['grant']->public_id, 'kind' => 'contract'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();
        $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertSame([0], $streams->transactionLevels);
        foreach ($streams->resources as $resource) {
            $this->assertFalse(is_resource($resource));
        }
    }

    public function test_withdrawal_after_redemption_preparation_cannot_consume_attempt_or_leak_spool(): void
    {
        $f = F::account();
        $paid = F::ready($f['user']);
        $this->login($f);
        $url = '/orders/'.$paid['order']->public_id.'/delivery';
        $authorization = $this->postJson($url.'/authorizations', ['grantId' => $paid['grant']->public_id, 'kind' => 'contract'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('authorization');
        $streams = DeliveryFixtures::observingStreams();
        $streams->afterPrepare = fn () => F::withdraw($f);
        $this->app->instance(PrepareTestDeliveryStream::class, $streams);
        $body = http_build_query(['authorizationId' => $authorization['authorizationId'], 'token' => $authorization['token'], '_token' => session()->token()]);
        $this->call('POST', $url.'/download', [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $body)->assertForbidden();
        $this->assertDatabaseCount('test_delivery_redemptions', 0);
        $this->assertSame([0], $streams->transactionLevels);
        foreach ($streams->resources as $resource) {
            $this->assertFalse(is_resource($resource));
        }
    }

    public function test_withdrawal_during_provider_account_check_prevents_checkout_create(): void
    {
        $f = F::account();
        CheckoutFixtures::configure();
        $order = F::prepared($f['user'], configured: true);
        $this->login($f);
        $gateway = PaymentFixtures::gateway();
        $adapter = new class($gateway, fn () => F::withdraw($f)) implements StripeCheckoutGateway
        {
            public function __construct(private object $gateway, private mixed $withdraw) {}

            public function account(): array
            {
                $result = $this->gateway->account();
                ($this->withdraw)();

                return $result;
            }

            public function create(array $params, string $idempotencyKey): array
            {
                return $this->gateway->create($params, $idempotencyKey);
            }

            public function retrieve(string $id): array
            {
                return $this->gateway->retrieve($id);
            }
        };
        $this->app->instance(StripeCheckoutGateway::class, $adapter);
        $this->call('POST', '/orders/'.$order->public_id.'/checkout', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertForbidden();
        $this->assertSame(['account'], array_column($gateway->calls, 'operation'));
        $this->assertDatabaseCount('checkout_sessions', 0);
        $this->assertDatabaseCount('checkout_intents', 1); // The already-authorized intent remains recoverable.
    }

    public function test_inflight_provider_observation_is_retained_but_never_returned_after_withdrawal(): void
    {
        $f = F::account();
        CheckoutFixtures::configure();
        $order = F::prepared($f['user'], configured: true);
        $this->login($f);
        $gateway = PaymentFixtures::gateway();
        $gateway->onCreate = function (array $params) use ($f): array {
            F::withdraw($f);

            return CheckoutFixtures::session($params);
        };
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->call('POST', '/orders/'.$order->public_id.'/checkout', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertForbidden()->assertDontSee('checkout.stripe.com');
        $this->assertDatabaseCount('checkout_sessions', 1);
        $this->assertDatabaseCount('checkout_observations', 1);
        $this->assertSame([0, 0], array_column($gateway->calls, 'transaction_level'));
    }

    public function test_withdrawal_during_a_private_projection_is_checked_again_before_response(): void
    {
        $f = F::account();
        $order = F::prepared($f['user']);
        $this->login($f);
        $changed = false;
        DB::listen(function ($query) use ($f, &$changed): void {
            if (! $changed && str_starts_with(strtolower($query->sql), 'select') && preg_match('/from [`"]orders[`"]/', $query->sql)) {
                $changed = true;
                F::withdraw($f);
            }
        });
        $this->getJson('/orders/'.$order->public_id.'/status')->assertForbidden()->assertDontSee($order->public_id)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue($changed);
        $this->assertDatabaseCount('orders', 1);
    }

    private function login(array $fixture): void
    {
        $this->postJson('/account/sign-in', ['email' => $fixture['user']->email, 'password' => F::PASSWORD])->assertOk();
    }
}
