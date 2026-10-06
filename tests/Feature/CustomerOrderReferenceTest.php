<?php

namespace Tests\Feature;

use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOwnedTestOrders;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Contracts\ContractRenderer;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\CustomerFixtures as F;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class CustomerOrderReferenceTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        F::configure();
        OrderFixtures::configure();
        $this->travelTo(now()->startOfSecond());
    }

    public function test_account_can_lookup_its_older_reference_without_loading_another_history_page(): void
    {
        $customer = F::account();
        $older = F::prepared($customer['user']);
        for ($i = 0; $i < ReadOwnedTestOrders::LIMIT; $i++) {
            F::prepared($customer['user']);
        }
        $this->login($customer);

        $this->assertReadOnly(function () use ($older, $customer): void {
            $history = $this->getJson('/orders/history')->assertOk()->assertJsonCount(20, 'history.orders')->json('history');
            $this->assertNotNull($history['nextCursor']);
            $this->assertNotContains($older->public_id, array_column($history['orders'], 'id'));
            // The customer supplies the exact retained reference without requesting the next page first.
            $response = $this->getJson('/orders/'.$older->public_id.'/status')->assertOk()
                ->assertJsonPath('order.id', $older->public_id)->assertJsonPath('order.orderSchema', 1)
                ->assertJsonPath('order.testOnly', true)->assertJsonPath('order.payable', false)
                ->assertJsonPath('order.status', 'prepared')->assertDontSee($customer['principal']->ownerKey, false);
            $this->assertPrivate($response);
            $this->getJson('/orders/'.$older->public_id.'/status')->assertOk()->assertExactJson($response->json());
        });
    }

    public function test_foreign_customer_guest_and_unknown_references_have_identical_private_not_found_responses(): void
    {
        // Matching buyer email remains insufficient to adopt an earlier guest order.
        $customer = F::account(['email' => OrderFixtures::buyer()['email']]);
        $other = F::account();
        $foreign = F::prepared($other['user']);
        $selection = InventoryFixtures::selection();
        $quote = $selection['quote'];
        app(PriceQuote::class)->create($quote->public_id, InventoryFixtures::OWNER);
        $guest = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), OrderFixtures::request($quote));
        $this->login($customer);

        $this->assertReadOnly(function () use ($customer, $other, $foreign, $guest): void {
            $responses = [];
            foreach ([$foreign->public_id, $guest->public_id, (string) Str::uuid()] as $id) {
                $response = $this->getJson('/orders/'.$id.'/status')->assertNotFound()
                    ->assertExactJson(['code' => 'ORDER_NOT_FOUND', 'message' => 'This order review is unavailable.']);
                $this->assertPrivate($response);
                foreach ([$id, $foreign->public_id, $guest->public_id, $customer['principal']->ownerKey,
                    $other['principal']->ownerKey, InventoryFixtures::OWNER, ...array_values(OrderFixtures::buyer())] as $private) {
                    $response->assertDontSee($private, false);
                }
                $responses[] = $response->getContent();
            }
            $this->assertSame($responses[0], $responses[1]);
            $this->assertSame($responses[0], $responses[2]);
        });
    }

    public function test_withdrawn_account_cannot_lookup_a_previously_accessible_reference(): void
    {
        $customer = F::account();
        $order = F::prepared($customer['user']);
        $this->login($customer);
        $this->getJson('/orders/'.$order->public_id.'/status')->assertOk()->assertJsonPath('order.id', $order->public_id);
        F::withdraw($customer);

        $this->assertReadOnly(function () use ($customer, $order): void {
            $response = $this->getJson('/orders/'.$order->public_id.'/status')->assertForbidden()
                ->assertJsonPath('code', 'CUSTOMER_SIGN_IN_UNAVAILABLE')->assertDontSee($order->public_id, false)
                ->assertDontSee($customer['principal']->ownerKey, false);
            $this->assertPrivate($response);
        });
    }

    public function test_paid_reference_reads_preserve_originals_and_do_not_initiate_checkout_rendering_or_delivery(): void
    {
        $customer = F::account();
        $paid = F::ready($customer['user'], 'REFERENCE');
        $this->login($customer);
        $this->assertDatabaseCount('grant_contracts', 1);
        $this->assertDatabaseCount('test_fulfillment_activations', 1);
        $this->assertDatabaseCount('test_delivery_authorizations', 0);
        $this->assertDatabaseCount('test_delivery_redemptions', 0);

        $this->assertReadOnly(function () use ($paid): void {
            $response = $this->getJson('/orders/'.$paid['order']->public_id.'/status')->assertOk()
                ->assertJsonPath('order.id', $paid['order']->public_id)->assertJsonPath('order.status', 'paid')
                ->assertJsonPath('order.paymentStatus', 'verified')->assertJsonPath('order.finalizationStatus', 'paid')
                ->assertJsonPath('order.contractStatus', 'issued')->assertJsonPath('order.fulfillmentStatus', 'pending_activation');
            $this->assertPrivate($response);
            $this->getJson('/orders/'.$paid['order']->public_id.'/status')->assertOk()->assertExactJson($response->json());
        });
    }

    private function login(array $customer): void
    {
        $this->postJson('/account/sign-in', ['email' => $customer['user']->email, 'password' => F::PASSWORD])->assertOk();
    }

    private function assertPrivate(TestResponse $response): void
    {
        $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    private function assertReadOnly(callable $read): void
    {
        $before = $this->retained();
        $files = $this->privateFiles();
        $this->mock(StripeCheckoutGateway::class, fn ($mock) => $mock->shouldNotReceive('account', 'create', 'retrieve'));
        $this->mock(StripePaymentGateway::class, fn ($mock) => $mock->shouldNotReceive('account', 'retrieve', 'paymentIntent'));
        $this->mock(ContractRenderer::class, fn ($mock) => $mock->shouldNotReceive('render'));
        $observing = true;
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$observing, &$writes): void {
            if ($observing && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        try {
            $read();
        } finally {
            $observing = false;
        }
        $this->assertSame([], $writes, 'Exact-reference reads must not mutate database state.');
        $this->assertSame($before, $this->retained());
        $this->assertSame($files, $this->privateFiles());
    }

    private function retained(): array
    {
        $rows = DeliveryFixtures::retained();
        foreach (['customer_accounts', 'checkout_intents', 'checkout_sessions', 'checkout_observations', 'test_delivery_controls',
            'test_delivery_authorizations', 'test_delivery_redemptions', 'audit_events'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    private function privateFiles(): array
    {
        $disk = Storage::disk('local');
        $files = [];
        foreach ($disk->allFiles() as $path) {
            $files[$path] = hash_file('sha256', $disk->path($path));
            $this->assertIsString($files[$path]);
        }
        ksort($files);

        return $files;
    }
}
