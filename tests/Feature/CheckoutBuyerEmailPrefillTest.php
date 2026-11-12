<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\CheckoutObservation;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\ProcessStripeReceipt;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutFixtures;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\InventoryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PaymentFixtures as F;
use Tests\TestCase;

/**
 * Hosted test Checkout pre-fills Stripe's email field with the address the buyer gave for this order; stored requests from
 * before the prefill replay byte for byte without it. A status click on an order whose payment is already verified answers
 * from local evidence without another provider read.
 */
class CheckoutBuyerEmailPrefillTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    private StripePaymentGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
        Queue::fake();
        $this->gateway = F::gateway();
        $this->app->instance(StripeCheckoutGateway::class, $this->gateway);
        $this->app->instance(StripePaymentGateway::class, $this->gateway);
    }

    private function creates(): array
    {
        return array_values(array_filter($this->gateway->calls, fn ($call) => $call['operation'] === 'create'));
    }

    /** A guest order whose buyer typed `$email`; it passed order validation (FILTER_VALIDATE_EMAIL). */
    private function preparedWithEmail(string $email): array
    {
        $f = OrderFixtures::priced();
        $request = OrderFixtures::request($f['quote']);
        $request['buyer']['email'] = $email;
        $order = app(PrepareOrder::class)->handle(InventoryFixtures::OWNER, (string) Str::uuid(), $request);

        return $f + ['order' => $order->refresh()];
    }

    /** Stores an intent exactly as start() did before the prefill shipped: its request has no `customer_email`. */
    private function legacyIntent(array $f, ?string $storedEmail = null, bool $withKey = false): CheckoutIntent
    {
        $evidence = app(CheckoutEvidence::class);
        $order = $f['order'];
        $attempt = $order->attempt()->sole();
        $at = now()->toImmutable()->utc()->startOfSecond();
        $publicId = '00000000-0000-4000-8000-000000000412';
        $payload = $evidence->request($order, app(ReadOrder::class)->verify($order), $publicId, CheckoutFixtures::policy(), CheckoutFixtures::ACCOUNT, $at);
        unset($payload['params']['customer_email']);
        if ($withKey) {
            $payload['params']['customer_email'] = $storedEmail;
        }
        [$ciphertext, $hash] = $evidence->encrypt($payload);

        return CheckoutIntent::create(['public_id' => $publicId, 'order_id' => $order->id, 'order_attempt_id' => $attempt->id,
            'account_id' => CheckoutFixtures::ACCOUNT, 'mode' => 'test', 'idempotency_key' => 'vasey-checkout-v1-'.$publicId,
            'request_ciphertext' => $ciphertext, 'request_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
            'created_at' => $at, 'initiate_before' => $attempt->expires_at, 'retry_before' => $at->addSeconds(900),
            'provider_expires_at' => $at->addSeconds(3600)]);
    }

    public function test_guest_order_prefills_its_own_buyer_email_and_stores_it_only_in_the_encrypted_request(): void
    {
        $f = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $params = $this->creates()[0]['params'];
        $this->assertSame(OrderFixtures::buyer()['email'], $params['customer_email']);
        $this->assertArrayNotHasKey('customer', $params);
        $this->assertSame('if_required', $params['customer_creation']);
        $intent = CheckoutIntent::sole();
        $this->assertStringNotContainsString(OrderFixtures::buyer()['email'], json_encode($intent->getAttributes(), JSON_THROW_ON_ERROR));
        // The frozen request replays with the email, so a retry sends the same params under the same key.
        $this->assertSame($params, app(CheckoutEvidence::class)->verifyIntent($intent, $f['order'])['params']);
    }

    public function test_signed_in_order_prefills_the_email_given_for_the_order_not_the_account_email(): void
    {
        $customer = CustomerFixtures::account(['email' => 'account-login@example.com']);
        $order = CustomerFixtures::prepared($customer['user'], configured: true);
        app(HostedCheckout::class)->start($order->public_id, $customer['principal']->ownerKey, $customer['user'], $customer['principal']);
        $params = $this->creates()[0]['params'];
        $this->assertSame(OrderFixtures::buyer()['email'], $params['customer_email']);
        $this->assertArrayNotHasKey('customer', $params);
        $this->assertStringNotContainsString('account-login@example.com', json_encode($params, JSON_THROW_ON_ERROR));
    }

    public static function unsentShapes(): array
    {
        return ['ip literal domain' => ['buyer@[127.0.0.1]'], 'one-letter top-level label' => ['buyer@example.c'], 'digit in top-level label' => ['buyer@example.x1']];
    }

    #[DataProvider('unsentShapes')]
    public function test_an_address_outside_the_conservative_shape_is_not_sent_and_the_buyer_types_it_on_stripe(string $email): void
    {
        $this->assertNotFalse(filter_var($email, FILTER_VALIDATE_EMAIL));
        $f = $this->preparedWithEmail($email);
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $params = $this->creates()[0]['params'];
        $this->assertArrayNotHasKey('customer_email', $params);
        $this->assertSame($params, app(CheckoutEvidence::class)->verifyIntent(CheckoutIntent::sole(), $f['order'])['params']);
    }

    public function test_buyer_email_shape(): void
    {
        foreach (['buyer@example.com', "o'brien+tag@mail.example.co.uk", 'A.B@example.museum'] as $email) {
            $this->assertSame($email, CheckoutEvidence::buyerEmail($email));
        }
        foreach ([null, 7, '', 'buyer@localhost', 'buyer@[127.0.0.1]', 'buyer@example.123', 'bü@example.com', '"a b"@example.com',
            'buyer@-example.com', 'buyer@example.com ', str_repeat('a', 65).'@example.com', 'a@'.str_repeat('b', 250).'.com'] as $email) {
            $this->assertNull(CheckoutEvidence::buyerEmail($email), var_export($email, true));
        }
    }

    public function test_intent_stored_before_the_prefill_replays_its_original_request_byte_for_byte(): void
    {
        $f = CheckoutFixtures::prepared();
        $intent = $this->legacyIntent($f);
        $stored = app(CheckoutEvidence::class)->decrypt($intent->request_ciphertext, $intent->request_hash, $intent->canonicalization_version);
        $this->assertArrayNotHasKey('customer_email', $stored['params']);
        $this->assertSame(CanonicalJson::encode($stored), CanonicalJson::encode(app(CheckoutEvidence::class)->verifyIntent($intent, $f['order'])));
        // The lost first create is retried inside its window with the stored params and key, not a newly built request.
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $creates = $this->creates();
        $this->assertCount(1, $creates);
        $this->assertSame(CanonicalJson::encode($stored['params']), CanonicalJson::encode($creates[0]['params']));
        $this->assertSame('vasey-checkout-v1-'.$intent->public_id, $creates[0]['key']);
        $this->assertArrayNotHasKey('customer_email', $creates[0]['params']);
    }

    public static function alteredStored(): array
    {
        return ['different address' => ['other@example.com'], 'null' => [null], 'empty' => ['']];
    }

    #[DataProvider('alteredStored')]
    public function test_stored_email_that_is_not_the_orders_conforming_address_is_refused(?string $stored): void
    {
        $f = CheckoutFixtures::prepared();
        $intent = $this->legacyIntent($f, $stored, true);
        $this->expectRefused(fn () => app(CheckoutEvidence::class)->verifyIntent($intent, $f['order']));
    }

    public function test_stored_email_is_refused_when_the_order_address_would_not_be_sent(): void
    {
        $f = $this->preparedWithEmail('buyer@[127.0.0.1]');
        $intent = $this->legacyIntent($f, 'buyer@[127.0.0.1]', true);
        $this->expectRefused(fn () => app(CheckoutEvidence::class)->verifyIntent($intent, $f['order']));
    }

    private function expectRefused(callable $operation): void
    {
        try {
            $operation();
            $this->fail('An altered stored request verified.');
        } catch (QuoteException $error) {
            $this->assertSame('CHECKOUT_CHANGED', $error->errorCode);
        }
    }

    public function test_status_click_on_a_verified_order_answers_locally_without_a_provider_read(): void
    {
        $f = F::started($this->gateway);
        $this->assertSame('awaiting_finalization', app(ProcessStripeReceipt::class)->handle(F::receipt($this->gateway->session)->id));
        $calls = count($this->gateway->calls);
        $observations = CheckoutObservation::count();
        $status = app(HostedCheckout::class)->status($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame('verified', $status['paymentStatus']);
        // Even a provider that would now report something else is not consulted.
        $this->gateway->onRetrieve = fn () => $this->fail('A verified order was re-read from the provider.');
        $result = app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER);
        $this->assertSame($status, $result);
        $this->assertSame($calls, count($this->gateway->calls));
        $this->assertSame($observations, CheckoutObservation::count());
    }

    public static function unverified(): array
    {
        return ['open' => ['open', 'unpaid'], 'complete but unverified' => ['complete', 'paid'], 'expired' => ['expired', 'unpaid'],
            'payment failed' => ['complete', 'unpaid']];
    }

    #[DataProvider('unverified')]
    public function test_status_click_on_an_unverified_order_still_reads_the_provider(string $status, string $paymentStatus): void
    {
        $f = CheckoutFixtures::prepared();
        app(HostedCheckout::class)->start($f['order']->public_id, InventoryFixtures::OWNER);
        $this->gateway->session['status'] = $status;
        $this->gateway->session['payment_status'] = $paymentStatus;
        if ($status !== 'open') {
            $this->gateway->session['url'] = null;
        }
        $before = count($this->gateway->calls);
        $result = app(HostedCheckout::class)->reconcile($f['order']->public_id, InventoryFixtures::OWNER);
        $retrieves = array_filter(array_slice($this->gateway->calls, $before), fn ($call) => $call['operation'] === 'retrieve');
        $this->assertCount(1, $retrieves);
        $this->assertSame($status, $result['status']);
        $this->assertSame('not_verified', $result['paymentStatus']);
    }
}
