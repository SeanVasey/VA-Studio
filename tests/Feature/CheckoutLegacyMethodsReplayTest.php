<?php

namespace Tests\Feature;

use App\Domain\Commerce\Checkout\CheckoutEvidence;
use App\Domain\Commerce\Checkout\CheckoutPaymentMethods;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Payments\PaymentEvidence;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\QuoteException;
use App\Support\CanonicalJson;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;

/**
 * BL-68 review P1 and P2: stored card-only requests replay through verifyIntent(), the session and PaymentIntent
 * checks hold the same unordered exact-set rule for old and new requests, and a superset is never accepted.
 */
class CheckoutLegacyMethodsReplayTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->fakePrivateMediaStorage();
        $this->travelTo(now()->startOfSecond());
        F::configure();
    }

    /** Stores an intent whose request asked for `$methods`, exactly as HostedCheckout::start() would have. */
    private function intentAsking(array $f, mixed $methods): CheckoutIntent
    {
        $evidence = app(CheckoutEvidence::class);
        $order = $f['order'];
        $attempt = $order->attempt()->sole();
        $at = now()->toImmutable()->utc()->startOfSecond();
        $publicId = '00000000-0000-4000-8000-000000000068';
        $payload = $evidence->request($order, app(ReadOrder::class)->verify($order), $publicId, F::policy(), F::ACCOUNT, $at);
        $payload['params']['payment_method_types'] = $methods;
        [$ciphertext, $hash] = $evidence->encrypt($payload);

        return CheckoutIntent::create(['public_id' => $publicId, 'order_id' => $order->id, 'order_attempt_id' => $attempt->id,
            'account_id' => F::ACCOUNT, 'mode' => 'test', 'idempotency_key' => 'vasey-checkout-v1-'.$publicId,
            'request_ciphertext' => $ciphertext, 'request_hash' => $hash, 'canonicalization_version' => CanonicalJson::VERSION,
            'created_at' => $at, 'initiate_before' => $attempt->expires_at, 'retry_before' => $at->addSeconds(900),
            'provider_expires_at' => $at->addSeconds(3600)]);
    }

    public function test_stored_card_only_intent_verifies_and_keeps_its_legacy_request(): void
    {
        $f = F::prepared();
        $intent = $this->intentAsking($f, ['card']);
        $request = app(CheckoutEvidence::class)->verifyIntent($intent, $f['order']);
        $this->assertSame(['card'], $request['params']['payment_method_types']);
    }

    public function test_stored_card_and_link_intent_verifies(): void
    {
        $f = F::prepared();
        $request = app(CheckoutEvidence::class)->verifyIntent($this->intentAsking($f, ['card', 'link']), $f['order']);
        $this->assertSame(['card', 'link'], $request['params']['payment_method_types']);
    }

    public static function refusedStored(): array
    {
        return [[['link']], [['card', 'klarna']], [['link', 'card']], [['card', 'link', 'klarna']], [['card', 'card']], [['us_bank_account']], [[]], [null], ['card']];
    }

    #[DataProvider('refusedStored')]
    public function test_stored_intent_with_any_other_method_list_is_refused(mixed $methods): void
    {
        $f = F::prepared();
        $intent = $this->intentAsking($f, $methods);
        try {
            app(CheckoutEvidence::class)->verifyIntent($intent, $f['order']);
            $this->fail('A stored request with an unexpected method list verified.');
        } catch (QuoteException $error) {
            $this->assertSame('CHECKOUT_CHANGED', $error->errorCode);
        }
    }

    private function request(array $f, array $methods): array
    {
        $request = app(CheckoutEvidence::class)->request($f['order'], app(ReadOrder::class)->verify($f['order']),
            '00000000-0000-4000-8000-000000000068', F::policy(), F::ACCOUNT, now()->toImmutable()->utc()->startOfSecond());
        $request['params']['payment_method_types'] = $methods;

        return $request;
    }

    private function assertSessionRefused(array $session, array $request): void
    {
        try {
            app(CheckoutEvidence::class)->session($session, $request);
            $this->fail('Session evidence with the wrong payment methods was accepted.');
        } catch (QuoteException $error) {
            $this->assertSame('CHECKOUT_CHANGED', $error->errorCode);
        }
    }

    private function assertPaymentRefused(array $session, array $payment, array $request): void
    {
        $session['status'] = 'complete';
        $session['payment_status'] = 'paid';
        $session['url'] = null;
        $session['payment_intent'] = $payment['id'];
        try {
            app(PaymentEvidence::class)->capture($session, $payment, $request, 'reconciliation', null);
            $this->fail('PaymentIntent evidence with the wrong payment methods was accepted.');
        } catch (PaymentVerificationException $error) {
            $this->assertSame('payment_changed', $error->reason);
        }
    }

    public static function observedAgainst(): array
    {
        // [requested, observed, accepted]
        return [
            'new_exact' => [['card', 'link'], ['card', 'link'], true],
            'new_reordered' => [['card', 'link'], ['link', 'card'], true],
            'new_superset_klarna' => [['card', 'link'], ['card', 'link', 'klarna'], false],
            'new_card_only' => [['card', 'link'], ['card'], false],
            'new_link_only' => [['card', 'link'], ['link'], false],
            'new_duplicate' => [['card', 'link'], ['card', 'card', 'link'], false],
            'legacy_exact' => [['card'], ['card'], true],
            'legacy_given_link' => [['card'], ['card', 'link'], false],
            'legacy_superset_klarna' => [['card'], ['card', 'klarna'], false],
            'legacy_link_only' => [['card'], ['link'], false],
        ];
    }

    #[DataProvider('observedAgainst')]
    public function test_session_methods_must_equal_the_requested_set(array $requested, array $observed, bool $accepted): void
    {
        $f = F::prepared();
        $request = $this->request($f, $requested);
        $session = F::session($request['params']);
        $session['payment_method_types'] = $observed;
        if ($accepted) {
            $this->assertSame('open', app(CheckoutEvidence::class)->session($session, $request)['status']);
        } else {
            $this->assertSessionRefused($session, $request);
        }
    }

    #[DataProvider('observedAgainst')]
    public function test_payment_intent_methods_must_equal_the_requested_set(array $requested, array $observed, bool $accepted): void
    {
        $f = F::prepared();
        $request = $this->request($f, $requested);
        $session = F::session($request['params']);
        $payment = PaymentFixtures::payment($session);
        $payment['payment_method_types'] = $observed;
        if ($accepted) {
            $session['status'] = 'complete';
            $session['payment_status'] = 'paid';
            $session['url'] = null;
            $session['payment_intent'] = $payment['id'];
            $captured = app(PaymentEvidence::class)->capture($session, $payment, $request, 'reconciliation', null);
            $this->assertSame('confirmed', $captured['outcome'] ?? $captured['payment']['outcome'] ?? 'confirmed');
        } else {
            $this->assertPaymentRefused($session, $payment, $request);
        }
    }

    public function test_offered_list_is_the_builder_default(): void
    {
        $f = F::prepared();
        $request = app(CheckoutEvidence::class)->request($f['order'], app(ReadOrder::class)->verify($f['order']),
            '00000000-0000-4000-8000-000000000068', F::policy(), F::ACCOUNT, now()->toImmutable()->utc()->startOfSecond());
        $this->assertSame(CheckoutPaymentMethods::OFFERED, $request['params']['payment_method_types']);
    }
}
