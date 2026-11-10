<?php

namespace Tests\Feature\Merch;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Merch\Fulfillment\Printful\PrintfulClient;
use App\Domain\Merch\Fulfillment\RecordMerchFulfillment;
use App\Domain\Merch\Fulfillment\SubmitMerchFulfillment;
use App\Domain\Merch\Models\MerchFulfillment;
use App\Domain\Merch\Models\MerchFulfillmentEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakePrintfulClient;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures as F;
use Tests\TestCase;

/** Printful v2 webhook: signed with HMAC-SHA256 over the raw body, keyed by the hex-decoded secret (documented construction). */
class PrintfulWebhookTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private string $secretHex;

    private MerchFulfillment $fulfillment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        Http::preventStrayRequests();
        Queue::fake();
        F::configure();
        $gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $gateway);
        $this->app->instance(StripePaymentGateway::class, $gateway);
        $this->app->instance(PrintfulClient::class, new FakePrintfulClient);
        $this->secretHex = bin2hex(str_repeat('k', 24));
        config(['merch.printful_enabled' => true, 'merch.printful.webhook_secret_key' => $this->secretHex, 'merch.printful.webhook_public_key' => null]);

        $fixture = F::started($gateway);
        app(VerifyTestPayment::class)->reconcile($fixture['intent']);
        $recorded = app(RecordMerchFulfillment::class)->handle($fixture['order'],
            ['name' => 'Synthetic Buyer', 'address1' => '1 Synthetic Way', 'city' => 'Testville', 'country_code' => 'US', 'zip' => '50000'],
            [['source' => 'catalog', 'catalog_variant_id' => 4011, 'quantity' => 1, 'placements' => [['placement' => 'front']]]], 'STANDARD');
        $this->fulfillment = app(SubmitMerchFulfillment::class)->handle($recorded);
    }

    /** @param  array<string, mixed>  $data */
    private function event(string $type, array $data, string $at = '2026-11-10T12:00:00Z', int $retries = 0): string
    {
        return json_encode(['type' => $type, 'occurred_at' => $at, 'retries' => $retries, 'store_id' => 12, 'data' => $data], JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private function order(string $status = 'inprocess'): array
    {
        return ['order' => ['id' => (int) $this->fulfillment->printful_order_id, 'external_id' => $this->fulfillment->external_id, 'status' => $status]];
    }

    private function sign(string $body, ?string $secretHex = null): string
    {
        return hash_hmac('sha256', $body, (string) hex2bin($secretHex ?? $this->secretHex));
    }

    private function deliver(string $body, ?string $signature, array $headers = [])
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($signature !== null) {
            $server['HTTP_X_PF_WEBHOOK_SIGNATURE'] = $signature;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $this->call('POST', '/webhooks/printful', [], [], [], $server, $body);
    }

    public function test_an_invalid_or_missing_signature_is_refused_and_stores_nothing(): void
    {
        $body = $this->event('shipment_sent', $this->order('fulfilled') + ['shipment' => ['id' => 1, 'tracking_number' => 'T1']]);
        $this->deliver($body, null)->assertStatus(401)->assertJson(['code' => 'PRINTFUL_WEBHOOK_SIGNATURE_INVALID']);
        $this->deliver($body, str_repeat('0', 64))->assertStatus(401);
        $this->deliver($body, $this->sign($body, bin2hex(str_repeat('z', 24))))->assertStatus(401);
        $this->deliver($body.' ', $this->sign($body))->assertStatus(401);
        $this->assertSame(0, MerchFulfillmentEvent::query()->count());
        $this->assertSame(MerchFulfillment::SUBMITTED, $this->fulfillment->refresh()->state);
    }

    public function test_an_unconfigured_secret_refuses_everything(): void
    {
        config(['merch.printful.webhook_secret_key' => '']);
        $body = $this->event('order_updated', $this->order());
        $this->deliver($body, $this->sign($body))->assertStatus(503)->assertJson(['code' => 'PRINTFUL_WEBHOOK_NOT_CONFIGURED']);
        config(['merch.printful.webhook_secret_key' => 'not-hex']);
        $this->deliver($body, $this->sign($body))->assertStatus(503);
        $this->assertSame(0, MerchFulfillmentEvent::query()->count());
    }

    public function test_the_public_key_header_must_match_when_one_is_configured(): void
    {
        config(['merch.printful.webhook_public_key' => base64_encode('configuration-key')]);
        $body = $this->event('order_updated', $this->order());
        $this->deliver($body, $this->sign($body))->assertStatus(401);
        $this->deliver($body, $this->sign($body), ['x-pf-webhook-public-key' => base64_encode('other')])->assertStatus(401);
        $this->deliver($body, $this->sign($body), ['x-pf-webhook-public-key' => base64_encode('configuration-key')])->assertOk();
    }

    public function test_a_shipment_is_recorded_and_a_replay_changes_nothing(): void
    {
        $body = $this->event('shipment_sent', $this->order('fulfilled') + ['shipment' => ['id' => 31, 'tracking_number' => 'TRK123',
            'tracking_url' => 'https://tracking.example.test/TRK123']]);
        $this->deliver($body, $this->sign($body))->assertOk()->assertJson(['outcome' => 'applied']);
        $shipped = $this->fulfillment->refresh();
        $this->assertSame([MerchFulfillment::SHIPPED, 'TRK123', 'https://tracking.example.test/TRK123', 'fulfilled'],
            [$shipped->state, $shipped->tracking_number, $shipped->tracking_url, $shipped->printful_status]);
        $shippedAt = $shipped->shipped_at;

        $this->travel(2)->minutes();
        // Printful redelivers with a higher `retries`; the event is the same event.
        $retry = $this->event('shipment_sent', $this->order('fulfilled') + ['shipment' => ['id' => 31, 'tracking_number' => 'TRK123',
            'tracking_url' => 'https://tracking.example.test/TRK123']], retries: 3);
        $this->deliver($retry, $this->sign($retry))->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->deliver($body, $this->sign($body))->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->assertSame(1, MerchFulfillmentEvent::query()->count());
        $this->assertEquals($shippedAt, $this->fulfillment->refresh()->shipped_at);
    }

    public function test_a_failed_fulfillment_is_recorded_without_the_free_text_reason(): void
    {
        $body = $this->event('order_failed', ['reason' => 'Address for 1 Synthetic Way rejected'] + $this->order('failed'));
        $this->deliver($body, $this->sign($body))->assertOk()->assertJson(['outcome' => 'applied']);
        $failed = $this->fulfillment->refresh();
        $this->assertSame([MerchFulfillment::FAILED, 'PRINTFUL_ORDER_FAILED'], [$failed->state, $failed->failure_code]);
        $this->assertStringNotContainsString('Synthetic Way', json_encode(MerchFulfillmentEvent::query()->get()->toArray()));
        $this->assertStringNotContainsString('Synthetic Way', json_encode($failed->toArray()));
    }

    public function test_late_events_never_move_a_shipped_order_backwards(): void
    {
        $shipped = $this->event('shipment_sent', $this->order('fulfilled') + ['shipment' => ['id' => 31, 'tracking_number' => 'TRK1']]);
        $this->deliver($shipped, $this->sign($shipped))->assertOk();
        foreach (['order_failed', 'order_canceled', 'order_updated'] as $index => $type) {
            $late = $this->event($type, $this->order('late'), '2026-11-10T13:0'.$index.':00Z');
            $this->deliver($late, $this->sign($late))->assertOk()->assertJson(['outcome' => 'ignored']);
        }
        $this->assertSame([MerchFulfillment::SHIPPED, 'fulfilled'], [$this->fulfillment->refresh()->state, $this->fulfillment->printful_status]);
        $this->assertSame(4, MerchFulfillmentEvent::query()->count());
    }

    public function test_cancellation_and_status_updates_are_applied_forward_only(): void
    {
        $update = $this->event('order_updated', $this->order('inprocess'));
        $this->deliver($update, $this->sign($update))->assertOk()->assertJson(['outcome' => 'applied']);
        $this->assertSame([MerchFulfillment::SUBMITTED, 'inprocess'], [$this->fulfillment->refresh()->state, $this->fulfillment->printful_status]);
        $cancel = $this->event('order_canceled', $this->order('canceled'), '2026-11-10T14:00:00Z');
        $this->deliver($cancel, $this->sign($cancel))->assertOk()->assertJson(['outcome' => 'applied']);
        $this->assertSame(MerchFulfillment::CANCELED, $this->fulfillment->refresh()->state);
        $this->assertSame('ignored', $this->postOutcome('order_failed', $this->order('failed'), '2026-11-10T15:00:00Z'));
        $this->assertSame(MerchFulfillment::CANCELED, $this->fulfillment->refresh()->state);
    }

    private function postOutcome(string $type, array $data, string $at): string
    {
        $body = $this->event($type, $data, $at);

        return (string) $this->deliver($body, $this->sign($body))->assertOk()->json('outcome');
    }

    public function test_events_for_unknown_orders_and_unhandled_types_are_recorded_not_applied(): void
    {
        $foreign = $this->event('order_updated', ['order' => ['id' => 5, 'external_id' => 'someone-elses-order', 'status' => 'draft']]);
        $this->deliver($foreign, $this->sign($foreign))->assertOk()->assertJson(['outcome' => 'unmatched']);
        $other = $this->event('catalog_stock_updated', ['variants' => []]);
        $this->deliver($other, $this->sign($other))->assertOk()->assertJson(['outcome' => 'unmatched']);
        $this->assertSame(2, MerchFulfillmentEvent::query()->count());
        $this->assertSame(MerchFulfillment::SUBMITTED, $this->fulfillment->refresh()->state);
        $delivered = $this->event('shipment_delivered', $this->order('fulfilled') + ['shipment' => ['id' => 9]]);
        $this->deliver($delivered, $this->sign($delivered))->assertOk()->assertJson(['outcome' => 'ignored']);
    }

    public function test_malformed_oversized_and_disabled_requests_are_refused(): void
    {
        $garbage = 'not json';
        $this->deliver($garbage, $this->sign($garbage))->assertStatus(400)->assertJson(['code' => 'PRINTFUL_WEBHOOK_INVALID']);
        $big = $this->event('order_updated', ['pad' => str_repeat('x', 70000)]);
        $this->deliver($big, $this->sign($big))->assertStatus(413);

        config(['merch.printful_enabled' => false]);
        $body = $this->event('order_updated', $this->order());
        $this->deliver($body, $this->sign($body))->assertStatus(404);
        $this->assertSame(0, MerchFulfillmentEvent::query()->count());
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $last = null;
        for ($i = 0; $i < 61; $i++) {
            $last = $this->deliver('x', null);
        }
        $last->assertStatus(429);
    }

    public function test_the_stored_event_keeps_identifiers_and_a_hash_never_the_body(): void
    {
        $body = $this->event('order_updated', $this->order('inprocess') + ['recipient' => ['address1' => '1 Synthetic Way']]);
        $this->deliver($body, $this->sign($body))->assertOk();
        $row = MerchFulfillmentEvent::query()->sole();
        $this->assertSame(hash('sha256', $body), $row->body_sha256);
        $this->assertStringNotContainsString('Synthetic Way', json_encode($row->toArray()));
        $this->assertSame($this->fulfillment->id, $row->merch_fulfillment_id);
    }
}
