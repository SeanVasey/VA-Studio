<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\Quote;
use App\Domain\Commerce\Models\TestPaymentExceptionEvent;
use App\Domain\Commerce\Models\TestRefundResolution;
use App\Domain\Commerce\Operations\TestPaymentExceptionOperations;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Commerce\RefundResolution\ReadOwnedTestRefundResolution;
use App\Domain\Commerce\RefundResolution\ResolveRefundedTestException;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Customers\CustomerPurchaseClaims;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ContractFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\FinalizationFixtures;
use Tests\Support\InventoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFinancialFixtures;
use Tests\Support\PaymentFixtures;
use Tests\Support\RefundResolutionFixtures as F;
use Tests\TestCase;

class CustomerRefundResolutionTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private object $payments;

    private object $financial;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        CustomerFixtures::configure();
        F::configure();
        Queue::fake();
        $this->payments = PaymentFixtures::gateway();
        $this->financial = PaymentFinancialFixtures::gateway($this->payments);
        $this->financial->onInspect = F::fullRefund(...);
        $this->app->instance(StripeCheckoutGateway::class, $this->payments);
        $this->app->instance(StripePaymentGateway::class, $this->payments);
        $this->app->instance(StripeFinancialInspectionGateway::class, $this->financial);
    }

    public static function resourceShapes(): array
    {
        return ['inventory' => [false], 'inventory and promotion' => [true]];
    }

    #[DataProvider('resourceShapes')]
    public function test_retained_projection_survives_expiry_and_write_policy_withdrawal_without_changing_originals(bool $promoted): void
    {
        $f = F::exception($this->payments, $promoted);
        $read = app(ReadOwnedTestRefundResolution::class);
        $original = app(ReadOrder::class)->verify($f['order']);
        $status = app(ReadOrder::class)->present($f['order']);
        $this->assertNull($read->handle($f['order']->public_id, $f['order']->owner_key)['record']);
        $this->resolve($f);
        $resolution = TestRefundResolution::sole();
        $event = TestPaymentExceptionEvent::findOrFail($resolution->observed_event_id);
        $expected = ['exceptionResolutionSchema' => 1, 'orderId' => $f['order']->public_id, 'testOnly' => true,
            'record' => ['kind' => 'full_refund_verified_resources_released',
                'observedAt' => $event->observed_at->toIso8601ZuluString(), 'releasedAt' => $resolution->released_at->toIso8601ZuluString()]];
        app(TestPaymentExceptionOperations::class)->disposition($f['record']->public_id, $f['admin'], (string) Str::uuid(), 2, 'acknowledged');
        $this->travel(2)->days();
        config(['refund-resolution.enabled' => false, 'refund-resolution.policy' => null,
            'payments.stripe.processing_enabled' => false, 'payments.stripe.finalization_enabled' => false,
            'payments.stripe.checkout_enabled' => false]);
        $before = FinalizationFixtures::retained();
        $this->readOnly(function () use ($read, $f, $expected, $original, $status): void {
            $this->assertSame($expected, $read->handle($f['order']->public_id, $f['order']->owner_key));
            $this->assertSame($original, app(ReadOrder::class)->verify($f['order']));
            $this->assertSame($status, app(ReadOrder::class)->present($f['order']));
        });
        $this->assertSame($before, FinalizationFixtures::retained());
        $this->assertSame('paid_exception', $status['status']);
        $this->assertSame('verified', $status['paymentStatus']);
        $this->assertSame('blocked', $status['contractStatus']);
        $this->assertSame('blocked', $status['fulfillmentStatus']);
    }

    public static function owners(): array
    {
        return ['original guest' => [false], 'original account' => [true]];
    }

    #[DataProvider('owners')]
    public function test_original_owner_http_read_has_exact_private_shape_and_preserves_other_dtos(bool $account): void
    {
        $customer = $account ? CustomerFixtures::account() : null;
        if ($customer) {
            $this->login($customer);
        }
        $f = $this->ownedException($customer);
        $path = '/orders/'.$f['order']->public_id;
        $before = [];
        foreach (['status', 'items', 'checkout', 'delivery'] as $endpoint) {
            $response = $this->get($path.'/'.$endpoint);
            $before[$endpoint] = [$response->status(), $response->json()];
        }
        $history = $this->getJson('/orders/history')->assertOk()->json();
        $this->get($path.'/exception-resolution')->assertOk()->assertJsonPath('resolution.record', null);
        $this->resolve($f);
        $this->readOnly(function () use ($f, $path, $history, $before): void {
            $response = $this->get($path.'/exception-resolution')->assertOk();
            $this->assertSame(['resolution'], array_keys($response->json()));
            $response->assertExactJson(['resolution' => app(ReadOwnedTestRefundResolution::class)->handle($f['order']->public_id, $f['order']->owner_key)]);
            $response->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeaderMissing('ETag');
            $this->assertContains('Cookie', $response->baseResponse->getVary());
            foreach ([$f['order']->owner_key, $f['order']->payload_hash, $f['order']->payload_ciphertext,
                TestRefundResolution::sole()->public_id, 'pi_SYNTHETIC', 'ch_SYNTHETIC', 're_SYNTHETIC',
                'actor_id', 'evidence_hash', 'request_id', ...array_values(OrderFixtures::buyer())] as $private) {
                $response->assertDontSee($private, false);
            }
            foreach ($before as $endpoint => [$status, $body]) {
                $this->get($path.'/'.$endpoint)->assertStatus($status)->assertExactJson($body);
            }
            $this->getJson('/orders/history')->assertOk()->assertExactJson($history);
        });
        $this->withSession(['_quote_owner' => null, '_customer_access' => null]);
        auth('customer')->logout();
        $foreign = $this->get($path.'/exception-resolution')->assertNotFound();
        $unknown = $this->get('/orders/'.Str::uuid().'/exception-resolution')->assertNotFound();
        $this->assertSame($foreign->json(), $unknown->json());
    }

    public function test_account_withdrawal_during_projection_does_not_expose_a_retained_record(): void
    {
        $customer = CustomerFixtures::account();
        $this->login($customer);
        $f = $this->ownedException($customer);
        $this->resolve($f);
        $withdrawn = false;
        DB::listen(function (QueryExecuted $query) use ($customer, &$withdrawn): void {
            if (! $withdrawn && str_contains($query->sql, 'test_refund_resolutions')) {
                $withdrawn = true;
                CustomerFixtures::withdraw($customer);
            }
        });
        $this->get('/orders/'.$f['order']->public_id.'/exception-resolution')->assertForbidden()
            ->assertExactJson(['code' => 'TEST_RESOLUTION_UNAVAILABLE', 'message' => 'This test order resolution could not be loaded.']);
        $this->assertTrue($withdrawn);
        $this->get('/orders/'.$f['order']->public_id.'/exception-resolution')->assertForbidden();
    }

    public static function appearingProof(): array
    {
        return ['valid newly committed proof' => [false], 'corrupt newly selected proof' => [true]];
    }

    #[DataProvider('appearingProof')]
    public function test_exact_candidate_appearing_after_original_verification_is_fully_validated(bool $corrupt): void
    {
        $f = F::exception($this->payments, false);
        $originalReader = app(ReadOrder::class);
        $callback = function () use ($f, $corrupt): void {
            $this->resolve($f);
            if ($corrupt) {
                DB::unprepared('DROP TRIGGER refund_resolution_update');
                DB::table('test_refund_resolutions')->update(['evidence_ciphertext' => 'private-damaged-proof']);
            }
        };
        // The first real graph verification sees pending resources; the resolution appears only
        // afterward. Restore the real reader before executing the ordinary guarded write.
        $this->app->instance(ReadOrder::class, new class($originalReader, $callback)
        {
            public function __construct(private ReadOrder $reader, private \Closure $after) {}

            public function verify(Order $order): array
            {
                $original = $this->reader->verify($order);
                app()->instance(ReadOrder::class, $this->reader);
                ($this->after)();

                return $original;
            }
        });
        if ($corrupt) {
            $this->expectException(QuoteException::class);
        }
        $result = app(ReadOwnedTestRefundResolution::class)->handle($f['order']->public_id, $f['order']->owner_key);
        $this->assertSame('full_refund_verified_resources_released', $result['record']['kind']);
    }

    public static function damagedProofs(): array
    {
        return ['missing resolution' => ['missing'], 'corrupt resolution' => ['ciphertext'], 'future read clock' => ['future']];
    }

    #[DataProvider('damagedProofs')]
    public function test_present_or_required_damaged_proof_never_becomes_a_null_success(string $kind): void
    {
        $f = $this->ownedException();
        $this->resolve($f);
        if ($kind === 'missing') {
            DB::unprepared('DROP TRIGGER refund_resolution_delete');
            DB::table('test_refund_resolutions')->delete();
        } elseif ($kind === 'ciphertext') {
            DB::unprepared('DROP TRIGGER refund_resolution_update');
            DB::table('test_refund_resolutions')->update(['evidence_ciphertext' => 'private-damaged-proof']);
        } else {
            $this->travel(-1)->seconds();
        }
        $this->readOnly(fn () => $this->get('/orders/'.$f['order']->public_id.'/exception-resolution')->assertStatus(503)
            ->assertExactJson(['code' => 'TEST_RESOLUTION_UNAVAILABLE', 'message' => 'This test order resolution could not be loaded.']));
    }

    public function test_absence_for_a_prepared_order_does_not_assert_any_refund_result(): void
    {
        $customer = CustomerFixtures::account();
        $order = CustomerFixtures::prepared($customer['user'], configured: true);
        $this->readOnly(fn () => $this->assertSame(['exceptionResolutionSchema' => 1, 'orderId' => $order->public_id,
            'testOnly' => true, 'record' => null], app(ReadOwnedTestRefundResolution::class)->handle($order->public_id,
                $customer['principal']->ownerKey, $customer['principal'])));
    }

    public static function financialOnly(): array
    {
        return ['complete full refund observation only' => [true], 'partial refund observation only' => [false]];
    }

    #[DataProvider('financialOnly')]
    public function test_financial_observation_alone_is_not_a_retained_resolution(bool $full): void
    {
        $f = F::exception($this->payments, false);
        if (! $full) {
            $this->financial->onInspect = static function (array $source): array {
                $source = F::fullRefund($source);
                $amount = intdiv($source['payment']['amount'], 2);
                foreach (['charge_before', 'charge_after'] as $key) {
                    $source[$key]['amount_refunded'] = $amount;
                    $source[$key]['refunded'] = false;
                }
                $source['refunds']['data'][0]['amount'] = $amount;

                return $source;
            };
        }
        app(TestPaymentExceptionOperations::class)->reconcile($f['record']->public_id, $f['admin'], (string) Str::uuid(), 0);
        $this->assertDatabaseCount('test_payment_financial_observations', 1);
        $this->assertDatabaseCount('test_refund_resolutions', 0);
        $this->readOnly(fn () => $this->assertNull(app(ReadOwnedTestRefundResolution::class)
            ->handle($f['order']->public_id, $f['order']->owner_key)['record']));
    }

    public function test_legitimate_claimed_paid_order_returns_no_record_without_changing_claim_or_delivery_access(): void
    {
        DeliveryFixtures::configure();
        $this->app->instance(ContractRenderer::class, ContractFixtures::renderer());
        $f = DeliveryFixtures::ready($this->payments);
        $customer = CustomerFixtures::account();
        config(['customer.test_purchase_claims_enabled' => true]);
        $claims = app(CustomerPurchaseClaims::class);
        $marker = $claims->bind($claims->stage($f['order']->public_id, $f['order']->owner_key), $customer['principal']);
        $claims->complete($marker, $f['order']->public_id, $customer['principal'], $customer['user']);
        $this->login($customer);
        $path = '/orders/'.$f['order']->public_id;
        $before = $this->get($path.'/delivery')->assertOk()->json();
        $this->readOnly(function () use ($path, $before): void {
            $this->get($path.'/exception-resolution')->assertOk()->assertJsonPath('resolution.record', null);
            $this->get($path.'/delivery')->assertOk()->assertExactJson($before);
        });
        $this->assertDatabaseCount('customer_purchase_claims', 1);
        config(['customer.test_purchase_claims_enabled' => false]);
        $this->get($path.'/exception-resolution')->assertForbidden();
    }

    private function ownedException(?array $customer = null): array
    {
        $selection = InventoryFixtures::selection();
        $quoteId = $this->postJson('/quotes', ['items' => $selection['items']], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->json('quote.id');
        $quote = Quote::where('public_id', $quoteId)->sole();
        app(PriceQuote::class)->create($quoteId, $quote->owner_key);
        $orderId = $this->postJson('/orders', OrderFixtures::request($quote, $quote->owner_key), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->json('order.id');
        $order = Order::where('public_id', $orderId)->sole();
        app(HostedCheckout::class)->start($orderId, $order->owner_key, $customer['user'] ?? null, $customer['principal'] ?? null);
        $this->payments->session['status'] = 'complete';
        $this->payments->session['payment_status'] = 'paid';
        $this->payments->session['url'] = null;
        $this->payments->session['payment_intent'] = PaymentFixtures::PAYMENT;
        $this->payments->payment = PaymentFixtures::payment($this->payments->session);
        $this->travelTo($order->attempt()->sole()->expires_at->addSecond());
        $f = FinalizationFixtures::confirm(['order' => $order, 'intent' => CheckoutIntent::where('order_id', $order->id)->sole()]);
        $this->assertSame('paid_exception', app(FinalizeTestPayment::class)->handle($f['payment']->id));

        return $f + ['record' => OrderFinalization::where('order_id', $order->id)->sole(), 'admin' => LicenseFixtures::admin()];
    }

    private function resolve(array $f): void
    {
        $this->assertSame('released', app(ResolveRefundedTestException::class)->resolve($f['record']->public_id,
            $f['admin'], (string) Str::uuid(), 0)['status']);
    }

    private function login(array $customer): void
    {
        $this->actingAs($customer['user'], 'customer');
        $p = $customer['principal'];
        $this->withSession(['_customer_access' => ['account_id' => $p->accountId, 'access_version' => $p->accessVersion,
            'credential_stamp' => $p->credentialStamp]]);
    }

    private function readOnly(callable $read): void
    {
        Queue::fake();
        $calls = [$this->payments->calls, $this->financial->calls];
        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/\A\s*(insert|update|delete|replace|alter|drop|create)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        $read();
        $this->assertSame([], $writes);
        $this->assertSame($calls, [$this->payments->calls, $this->financial->calls]);
        Queue::assertNothingPushed();
    }
}
