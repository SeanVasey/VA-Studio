<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CheckoutSchema;
use App\Domain\Commerce\ProductionCheckout\CommandTransaction;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderLocatorV1;
use App\Domain\Commerce\ProductionCheckout\ProductionPaidOrderSourceV1;
use App\Domain\Commerce\ProductionCheckout\ProviderGateway;
use App\Domain\Commerce\ProductionCheckout\Records;
use App\Domain\Customers\ProductionIdentity\CompleteIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Customers\ProductionIdentity\Notifications\LoopbackSmtp;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Http\Middleware\ProductionCheckoutPrivacy;
use App\Providers\ProductionCheckoutServiceProvider;
use App\Support\CanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ProductionCheckoutJourneyFixture;
use Tests\TestCase;

/** Actual enrolled buyer/source/catalog/assent graph; provider facts remain explicitly synthetic. */
class ProductionCheckoutJourneyTest extends TestCase
{
    use FinalizationDatabaseMigrations;
    use ProductionCheckoutJourneyFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('j', 32)),
            'production-customer-identity.enabled' => true, 'production-customer-identity.provenance' => IdentityPolicy::REHEARSAL,
            'production-customer-identity.public_origin' => 'http://localhost', 'production-customer-identity.notifications_enabled' => true,
            'production-customer-identity.transport_capability' => LoopbackSmtp::CAPABILITY,
            'production_checkout.fresh_checkout_enabled' => true, 'production_checkout.reconciliation_enabled' => true]);
        Queue::fake();
    }

    public function test_actual_mailbox_enrollment_payable_assent_hosted_request_reconciliation_and_retained_paid_source(): void
    {
        $f = $this->payable();
        $this->assertSame(4999, $f['review']['amounts']['total_minor']);
        $this->assertFalse($f['review']['assent']['accepted']);
        $this->assertTrue($f['order']['assentAccepted']);
        $open = $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('unverified', $open['paymentStatus']);
        $this->assertCount(1, $f['gateway']->creates);
        $f['gateway']->paid = true;
        $paid = $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);
        $this->assertSame('pending_fulfillment', $paid['fulfillmentStatus']);
        $payments = DB::table(CheckoutSchema::TABLES['payment'])->get()->toJson();
        $this->assertSame($paid, $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']));
        $this->assertSame($payments, DB::table(CheckoutSchema::TABLES['payment'])->get()->toJson());
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($locator, $f): void {
            $historical = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current, $historical);
            $line = $source->line(1);
            $this->assertSame('synthetic_rehearsal', $line['provenance']);
            $this->assertSame(CanonicalJson::encode($f['buyer']['binding']), CanonicalJson::encode($line['buyer']));
            $this->assertTrue($line['assent']['accepted']);
            $this->assertSame($f['catalog']['revision']->license_version_id, $line['license_version_id']);
            $this->assertSame(4999, $line['line_amount_minor']);
            $this->assertSame(1, $source->lineCount());
            $source->proveRetainedCurrent($rows->current);
        });
        foreach (['orders', 'license_grants', 'checkout_intents', 'inventory_reservations'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_lost_create_response_retries_same_original_key_and_request_before_binding_one_session(): void
    {
        $f = $this->payable();
        $f['gateway']->loseFirstResponse = true;
        try {
            $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('Lost response silently confirmed.');
        } catch (CheckoutException $error) {
            $this->assertSame('provider_uncertain', $error->reason);
            $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
            $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 0);
            $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        }
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertCount(2, $f['gateway']->creates);
        $this->assertSame($f['gateway']->creates[0], $f['gateway']->creates[1]);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 1);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['session'], 1);
    }

    public function test_reconciliation_and_original_order_replay_remain_available_after_fresh_checkout_withdrawal(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        config(['production_checkout.fresh_checkout_enabled' => false]);
        $this->assertSame($f['order'], $f['checkout']->accept($f['buyer']['principal'], $f['buyer']['user'], $f['review']['reviewId'], $f['review']['reviewHash'], true, 'synthetic-accept'));
        $f['gateway']->paid = true;
        $paid = $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }

    public function test_first_confirmed_observation_after_attempt_deadline_is_retained_as_paid_exception(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(7201));
        try {
            $paid = $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->assertSame('verified', $paid['paymentStatus']);
            $this->assertSame('paid_exception', $paid['fulfillmentStatus']);
            $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
            $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
            try {
                CommandTransaction::run(function (Records $rows) use ($f, $locator): void {
                    $identity = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
                    ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current, $identity);
                });
                $this->fail('Paid exception became grant-ready source.');
            } catch (CheckoutException $error) {
                $this->assertSame('payment_required', $error->reason);
                $this->assertDatabaseCount('license_grants', 0);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_explicit_assent_exact_review_and_original_buyer_are_required_before_any_order_write(): void
    {
        $f = $this->payable(false);
        $other = $this->enrollThroughLocalSmtp('other-buyer@example.test');
        foreach ([[$f['buyer'], $f['review']['reviewHash'], false], [$f['buyer'], str_repeat('a', 64), true],
            [$other, $f['review']['reviewHash'], true]] as [$buyer, $hash, $accepted]) {
            try {
                $f['checkout']->accept($buyer['principal'], $buyer['user'], $f['review']['reviewId'], $hash, $accepted, 'synthetic-refused-accept');
                $this->fail('Unbound or unaccepted review produced an order.');
            } catch (CheckoutException) {
                $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 0);
                $this->assertDatabaseCount(CheckoutSchema::TABLES['line'], 0);
                $this->assertDatabaseCount(CheckoutSchema::TABLES['attempt'], 0);
            }
        }
    }

    public function test_recovery_during_provider_read_retains_confirmed_money_and_original_source_but_denies_old_principal(): void
    {
        $f = $this->payable();
        $f['hosted']->initiate($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
        $f['gateway']->paid = true;
        $replacement = 'ReplacementMailboxPassword123';
        $f['gateway']->afterPaymentRead = function () use ($replacement): void {
            $this->requestIdentity('recover');
            $id = (int) DB::table('production_identity_notices')->orderByDesc('id')->value('id');
            $received = $this->smtp('accept', $id);
            $this->assertSame(1, preg_match('~http://localhost/customer/access#recover\.([a-f0-9-]{36})\.([a-f0-9]{64})~', $received['data'], $match));
            (new CompleteIdentity)->complete($match[1], $match[2], $replacement, 'Declared buyer', str_repeat('c', 64));
        };
        try {
            $f['hosted']->reconcile($f['buyer']['principal'], $f['buyer']['user'], $f['order']['orderId']);
            $this->fail('Old principal projected a payment after credential recovery.');
        } catch (IdentityException) {
            $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
            $this->assertDatabaseCount('license_grants', 0);
        }
        $current = (new ProductionCustomerSessions)->authenticate('buyer@example.test', $replacement);
        $this->assertNotNull($current);
        $paid = $f['hosted']->status($current['principal'], $current['user'], $f['order']['orderId']);
        $this->assertSame('verified', $paid['paymentStatus']);
        $this->assertSame('pending_fulfillment', $paid['fulfillmentStatus']);
        $locator = ProductionPaidOrderLocatorV1::locate($f['order']['orderId']);
        CommandTransaction::run(function (Records $rows) use ($f, $locator): void {
            $proof = $f['access']->verifyHistoricalBinding($locator->historicalBuyerBinding(), $rows->current);
            $source = ProductionPaidOrderSourceV1::lockedRead($locator, $rows->current, $proof);
            $this->assertSame($f['buyer']['binding']['verification_observation_id'], $source->line(1)['buyer']['verification_observation_id']);
            $source->proveRetainedCurrent($rows->current);
        });
    }

    public function test_http_checkout_uses_real_new_sign_in_marker_and_a_return_never_creates_payment(): void
    {
        $f = $this->payable(false);
        $this->withoutVite();
        $this->app->register(ProductionCheckoutServiceProvider::class);
        $this->app->instance(ProviderGateway::class, $f['gateway']);
        $this->app[Kernel::class]->prependMiddleware(ProductionCheckoutPrivacy::class);
        Route::middleware('web')->group(base_path('routes/production-checkout.php'));
        Route::middleware('web')->group(base_path('routes/production-customer-identity.php'));
        config(['production_checkout.http_enabled' => true]);
        // A cached guard actor without the new authenticated credential marker has no checkout authority.
        $this->actingAs($f['buyer']['user'], 'customer');
        $body = ['reviewId' => $f['review']['reviewId'], 'reviewHash' => $f['review']['reviewHash'], 'accepted' => true, 'requestKey' => 'synthetic-http-order'];
        $this->postJson('/production/checkout/orders', $body)->assertForbidden();
        $this->assertDatabaseCount(CheckoutSchema::TABLES['order'], 0);
        $this->postJson('/customer/sign-in', ['email' => 'buyer@example.test', 'password' => 'MailboxPassword123'])->assertOk();
        $this->postJson('/production/checkout/orders', [...$body, 'accepted' => 'true'])->assertStatus(422);
        $order = $this->postJson('/production/checkout/orders', $body)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('checkout');
        $this->assertTrue($order['assentAccepted']);
        $this->get('/production/checkout/orders/'.$order['orderId'].'/return')->assertOk()->assertJsonPath('checkout.paymentStatus', 'unverified');
        $this->assertDatabaseCount(CheckoutSchema::TABLES['intent'], 0);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 0);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        $this->call('POST', '/production/checkout/orders/'.$order['orderId'].'/hosted', [], [], [], $headers, '{}')->assertOk();
        $this->assertCount(1, $f['gateway']->creates);
        $f['gateway']->paid = true;
        $this->call('POST', '/production/checkout/orders/'.$order['orderId'].'/reconcile', [], [], [], $headers, '{}')->assertOk()
            ->assertJsonPath('checkout.paymentStatus', 'verified')->assertJsonPath('checkout.fulfillmentStatus', 'pending_fulfillment');
        $this->get('/production/checkout/orders/'.$order['orderId'].'/return?payment_status=paid')->assertStatus(422);
        $this->assertDatabaseCount(CheckoutSchema::TABLES['payment'], 1);
        $this->assertDatabaseCount('license_grants', 0);
    }
}
