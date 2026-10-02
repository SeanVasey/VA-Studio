<?php

namespace Tests\Feature;

use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOwnedTestOrders;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\TestCase;

class OwnedTestOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp(); $this->withoutVite(); $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond()); OrderFixtures::configure();
    }

    private function sessionOwner(string $secret = 'a', string $context = 'guest'): string
    {
        $secret = str_repeat($secret, 64);
        $this->withSession(['_quote_owner' => ['context' => $context, 'secret' => $secret]]);

        return hash_hmac('sha256', "vasey-quote-owner-v1\0".$context."\0".$secret, config('app.key'));
    }

    private function order(string $owner): Order
    {
        $selection = InventoryFixtures::selection();
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), $selection['items']);
        app(PriceQuote::class)->create($quote->public_id, $owner);

        return app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), OrderFixtures::request($quote, $owner));
    }

    private function retained(): array
    {
        $rows = [];
        foreach (['orders', 'order_lines', 'order_attempts', 'inventory_reservations', 'promotion_uses',
            'checkout_intents', 'checkout_sessions', 'verified_payments', 'order_finalizations', 'license_grants',
            'pending_entitlements', 'fulfillment_outbox', 'contract_render_requests', 'contract_render_work', 'grant_contracts',
            'test_fulfillment_activations', 'test_delivery_controls', 'test_delivery_authorizations', 'test_delivery_redemptions', 'audit_events'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    public function test_owner_history_is_verified_private_and_read_only_after_creation_policy_withdrawal(): void
    {
        $owner = $this->sessionOwner();
        $order = $this->order($owner); $foreign = $this->order(str_repeat('b', 64));
        $before = $this->retained();
        $this->mock(StripePaymentGateway::class, fn ($mock) => $mock->shouldNotReceive('account', 'retrieve', 'paymentIntent'));
        $this->mock(StripeCheckoutGateway::class, fn ($mock) => $mock->shouldNotReceive('account', 'create', 'retrieve'));
        config(['commerce.test_order_policy' => null, 'payments.stripe.processing_enabled' => false,
            'payments.stripe.checkout_enabled' => false, 'filesystems.disks.local.root' => '/unavailable-private-history-root']);
        $response = $this->getJson('/orders/history')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Vary', 'Cookie')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $history = $response->json('history');
        $this->assertSame(['orderHistorySchema', 'testOnly', 'orders', 'limit', 'nextCursor'], array_keys($history));
        $this->assertSame(1, $history['orderHistorySchema']); $this->assertTrue($history['testOnly']);
        $this->assertSame(20, $history['limit']); $this->assertNull($history['nextCursor']); $this->assertCount(1, $history['orders']);
        $summary = $history['orders'][0];
        $this->assertSame(['id', 'createdAt', 'testOnly', 'payable', 'currency', 'totalMinor', 'status',
            'paymentStatus', 'finalizationStatus', 'contractStatus', 'fulfillmentStatus'], array_keys($summary));
        $this->assertSame($order->public_id, $summary['id']); $this->assertSame('prepared', $summary['status']);
        foreach ([$owner, $foreign->public_id, $order->payload_ciphertext, $order->payload_hash,
            $order->idempotency_key_hash, ...array_values(OrderFixtures::buyer())] as $private) {
            $response->assertDontSee($private, false);
        }
        $this->assertSame($before, $this->retained());
    }

    public function test_pagination_uses_created_time_then_id_and_loads_at_most_twenty_one_order_rows(): void
    {
        $owner = $this->sessionOwner(); $older = [];
        for ($i = 0; $i < 21; $i++) { $older[] = $this->order($owner)->public_id; }
        $this->travel(1)->seconds(); $newest = $this->order($owner)->public_id;
        $loaded = 0; Order::retrieved(function () use (&$loaded): void { $loaded++; });
        $page = $this->getJson('/orders/history')->assertOk()->json('history');
        $expected = [$newest, ...array_reverse($older)];
        $this->assertSame(array_slice($expected, 0, 20), array_column($page['orders'], 'id'));
        $this->assertSame($expected[19], $page['nextCursor']); $this->assertSame(21, $loaded);
        $newAfterPage = $this->order($owner)->public_id;
        $next = $this->getJson('/orders/history?before='.$page['nextCursor'])->assertOk()->json('history');
        $this->assertSame(array_slice($expected, 20), array_column($next['orders'], 'id'));
        $this->assertNull($next['nextCursor']); $this->assertNotContains($newAfterPage, array_column($next['orders'], 'id'));
    }

    public function test_a_twentieth_last_order_has_no_next_page_and_an_owned_cursor_is_a_read_only_locator(): void
    {
        $owner = $this->sessionOwner(); $orders = [];
        for ($i = 0; $i < 20; $i++) { $orders[] = $this->order($owner)->public_id; }
        $before = $this->retained();
        $this->getJson('/orders/history')->assertOk()->assertJsonCount(20, 'history.orders')->assertJsonPath('history.nextCursor', null);
        $this->getJson('/orders/history?before='.$orders[0])->assertOk()->assertJsonCount(0, 'history.orders');
        $this->assertSame($before, $this->retained());
    }

    public function test_another_session_and_authentication_context_never_adopt_order_ownership(): void
    {
        $owner = $this->sessionOwner(); $order = $this->order($owner);
        $before = $this->retained(); $this->sessionOwner('c');
        $this->getJson('/orders/history')->assertOk()->assertJsonCount(0, 'history.orders');
        $this->sessionOwner(); $user = User::factory()->create(); $this->actingAs($user);
        $this->getJson('/orders/history')->assertOk()->assertJsonCount(0, 'history.orders');
        // Supplying a former guest context after login still rotates; account identity is not an ownership shortcut.
        $this->assertNotSame($owner, $this->sessionOwner('d', 'user:'.$user->id));
        $this->getJson('/orders/history')->assertOk()->assertDontSee($order->public_id, false);
        $this->assertSame($before, $this->retained());
    }

    public function test_foreign_and_unknown_cursor_have_the_same_private_failure_without_graph_reads(): void
    {
        $owner = $this->sessionOwner(); $this->order($owner);
        $foreign = $this->order(str_repeat('b', 64)); $before = $this->retained();
        $foreignResponse = $this->getJson('/orders/history?before='.$foreign->public_id)->assertStatus(422);
        $unknownResponse = $this->getJson('/orders/history?before='.Str::uuid())->assertStatus(422);
        $this->assertSame($foreignResponse->json(), $unknownResponse->json());
        $foreignResponse->assertJsonPath('code', 'ORDER_HISTORY_CURSOR_INVALID')->assertDontSee($foreign->public_id, false)
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame($before, $this->retained());
    }

    public static function invalidQueries(): array
    {
        $id = '730000ab-0000-4000-8000-000000000001';
        return [['?before='], ['?before=bad'], ['?before[]='.$id], ['?before='.$id.'&before='.$id],
            ['?owner='.str_repeat('b', 64)], ['?before='.$id.'&page=2'], ['?before='.strtoupper($id)], ['?before=%37'.substr($id, 1)]];
    }

    #[DataProvider('invalidQueries')]
    public function test_ambiguous_or_noncanonical_cursors_are_rejected(string $query): void
    {
        $this->getJson('/orders/history'.$query)->assertStatus(422)->assertJsonPath('code', 'ORDER_HISTORY_CURSOR_INVALID')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_changed_retained_order_fails_the_list_closed_without_exposing_or_repairing_evidence(): void
    {
        $owner = $this->sessionOwner(); $order = $this->order($owner);
        DB::unprepared('DROP TRIGGER orders_immutable_update');
        DB::table('orders')->where('id', $order->id)->update(['payload_ciphertext' => 'PRIVATE_INVALID_FROZEN_EVIDENCE']);
        $before = $this->retained(); config(['app.debug' => true]); Log::spy();
        $this->getJson('/orders/history')->assertStatus(409)->assertJsonPath('code', 'ORDER_CHANGED')
            ->assertDontSee('PRIVATE_INVALID_FROZEN_EVIDENCE', false)->assertDontSee($owner, false)
            ->assertHeader('Cache-Control', 'no-store, private');
        Log::shouldNotHaveReceived('error'); $this->assertSame($before, $this->retained());
    }
}
