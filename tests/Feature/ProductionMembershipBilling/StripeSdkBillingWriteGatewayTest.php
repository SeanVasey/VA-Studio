<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingCheckoutParameters;
use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\StripeSdkBillingWriteGateway;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\BillingWriteHttpFixture;
use Tests\TestCase;

/**
 * The real SDK write gateway over a loopback transport: three endpoints only, own-account proof before any write, pinned wire version,
 * idempotency header, allowlisted parameters, redacted failures. Synthetic ids; no network, no database schema needed.
 */
class StripeSdkBillingWriteGatewayTest extends TestCase
{
    private const PLAN = '11111111-1111-4111-8111-111111111111';

    private const MEMBER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        F::configure(['provider_io_enabled' => true]);
    }

    public function test_checkout_session_is_one_idempotent_post_after_the_own_account_proof_with_the_pinned_wire_version(): void
    {
        $transport = new BillingWriteHttpFixture($this->routes());
        $session = (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($this->params(), $this->key());
        $this->assertSame(['GET /v1/account', 'POST /v1/checkout/sessions'], $transport->routes());
        $this->assertSame($this->key(), $transport->header(1, 'Idempotency-Key'));
        foreach ($transport->calls as $index => $call) {
            $this->assertSame('Stripe-Version: '.BillingProviderPin::API_VERSION, 'Stripe-Version: '.$transport->header($index, 'Stripe-Version'));
            $this->assertSame(0, $call['max_network_retries']);
        }
        $sent = $transport->calls[1]['params'];
        $this->assertSame(['subscription', 'flexible'], [$sent['mode'], $sent['subscription_data']['billing_mode']['type']]);
        $this->assertSame([['price' => F::PRICE, 'quantity' => 1]], $sent['line_items']);
        $this->assertArrayNotHasKey('customer', $sent);
        $this->assertSame(['cs_test_SYNTHETICWRITE', false, 'subscription', 'open', null], [$session['id'], $session['livemode'], $session['mode'], $session['status'], $session['customer']]);
        $this->assertSame($this->params()['metadata'], $session['metadata']);
    }

    public function test_portal_session_and_price_read_use_only_their_own_endpoints(): void
    {
        $transport = new BillingWriteHttpFixture($this->routes());
        $gateway = new StripeSdkBillingWriteGateway($transport);
        $portal = $gateway->createPortalSession(['customer' => F::CUSTOMER, 'return_url' => 'http://localhost/membership', 'configuration' => 'bpc_SYNTHETIC'], $this->portalKey());
        $price = $gateway->retrievePrice(F::PRICE);
        $this->assertSame(['GET /v1/account', 'POST /v1/billing_portal/sessions', 'GET /v1/account', 'GET /v1/prices/'.F::PRICE], $transport->routes());
        $this->assertSame($this->portalKey(), $transport->header(1, 'Idempotency-Key'));
        $this->assertSame(['bps_SYNTHETICWRITE', F::CUSTOMER, 'bpc_SYNTHETIC'], [$portal['id'], $portal['customer'], $portal['configuration']]);
        $this->assertSame([1700, 'usd', 'recurring'], [$price['unit_amount'], $price['currency'], $price['type']]);
    }

    public function test_a_key_in_another_account_is_refused_before_any_write(): void
    {
        $routes = $this->routes();
        $routes['GET /v1/account'] = ['id' => 'acct_SOMEBODYELSE', 'object' => 'account'];
        $transport = new BillingWriteHttpFixture($routes);
        $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($this->params(), $this->key()), 'account_mismatch');
        $this->assertSame(['GET /v1/account'], $transport->routes());
    }

    public function test_configuration_refusals_precede_every_request(): void
    {
        $cases = [['provider_io_enabled' => false, 'provider_io_disabled'], ['mode' => 'live', 'live_not_authorized'], ['enabled' => false, 'disabled'],
            ['secret_key' => 'sk_'.'live_'.'SYNTHETICREHEARSAL', 'provider_credential'], ['account_ref' => 'not-an-account', 'account_absent']];
        foreach ($cases as $case) {
            $reason = array_pop($case);
            F::configure(['provider_io_enabled' => true, ...$case]);
            $transport = new BillingWriteHttpFixture($this->routes());
            $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($this->params(), $this->key()), $reason);
            $this->assertSame([], $transport->calls, $reason);
        }
    }

    public function test_environment_outside_the_rehearsal_environments_is_refused_before_any_request(): void
    {
        foreach (['production', 'staging'] as $environment) {
            $this->app['env'] = $environment;
            $transport = new BillingWriteHttpFixture($this->routes());
            $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($this->params(), $this->key()), 'provenance');
            $this->assertSame([], $transport->calls, $environment);
        }
    }

    public function test_widened_parameters_and_foreign_keys_are_refused_before_any_request(): void
    {
        $base = $this->params();
        $widened = [
            'customer' => [...$base, 'customer' => F::CUSTOMER],
            'connect routing' => [...$base, 'payment_intent_data' => ['on_behalf_of' => 'acct_X']],
            'transfer' => [...$base, 'subscription_data' => [...$base['subscription_data'], 'transfer_data' => ['destination' => 'acct_X']]],
            'trial' => [...$base, 'subscription_data' => [...$base['subscription_data'], 'trial_period_days' => 30]],
            'discounts' => [...$base, 'discounts' => [['coupon' => 'FREE']]],
            'classic billing mode' => [...$base, 'subscription_data' => [...$base['subscription_data'], 'billing_mode' => ['type' => 'classic']]],
            'second line' => [...$base, 'line_items' => [...$base['line_items'], ['price' => 'price_OTHER', 'quantity' => 1]]],
            'quantity two' => [...$base, 'line_items' => [['price' => F::PRICE, 'quantity' => 2]]],
            'payment mode' => [...$base, 'mode' => 'payment'],
            'price data' => [...$base, 'line_items' => [['price_data' => ['currency' => 'usd', 'unit_amount' => 1], 'quantity' => 1]]],
        ];
        foreach ($widened as $name => $params) {
            $transport = new BillingWriteHttpFixture($this->routes());
            $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($params, $this->key()), 'write_shape', $name);
            $this->assertSame([], $transport->calls, $name);
        }
        foreach (['', 'va-membership-checkout-v1-short', 'free-text-key', $this->portalKey()] as $key) {
            $transport = new BillingWriteHttpFixture($this->routes());
            $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($base, $key), 'write_shape', $key);
            $this->assertSame([], $transport->calls);
        }
        $transport = new BillingWriteHttpFixture($this->routes());
        $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createPortalSession(['customer' => F::CUSTOMER, 'return_url' => 'http://localhost/m', 'flow_data' => ['type' => 'subscription_cancel']], $this->portalKey()), 'write_shape');
        $this->assertSame([], $transport->calls);
    }

    public function test_transport_failure_is_redacted_and_an_open_transaction_blocks_provider_io(): void
    {
        $routes = $this->routes();
        $routes['POST /v1/checkout/sessions'] = new RuntimeException('PRIVATE URL HEADER sk_test_SYNTHETICREHEARSAL BODY');
        $error = $this->refused(fn () => (new StripeSdkBillingWriteGateway(new BillingWriteHttpFixture($routes)))->createSubscriptionCheckoutSession($this->params(), $this->key()), 'provider_unavailable');
        $this->assertStringNotContainsString('PRIVATE', $error->getMessage().$error->getTraceAsString());
        $this->assertNull($error->getPrevious());
        DB::beginTransaction();
        try {
            $transport = new BillingWriteHttpFixture($this->routes());
            $this->refused(fn () => (new StripeSdkBillingWriteGateway($transport))->createSubscriptionCheckoutSession($this->params(), $this->key()), 'transaction_open');
            $this->assertSame([], $transport->calls);
        } finally {
            DB::rollBack();
        }
    }

    private function refused(callable $call, string $reason, string $context = ''): BillingException
    {
        try {
            $call();
        } catch (BillingException $error) {
            $this->assertSame($reason, $error->reason, $context);

            return $error;
        }
        $this->fail('Expected a refusal ('.$reason.') '.$context);
    }

    private function params(): array
    {
        $metadata = ['va_member_ref' => self::MEMBER, 'va_plan_version_id' => self::PLAN, 'va_purpose' => 'production_membership_subscription_checkout'];

        return BillingCheckoutParameters::subscription(F::PRICE, 'http://localhost/membership/done?s={CHECKOUT_SESSION_ID}', 'http://localhost/membership',
            'va-sub-'.str_repeat('c', 32), $metadata);
    }

    private function key(): string
    {
        return 'va-membership-checkout-v1-'.str_repeat('d', 64);
    }

    private function portalKey(): string
    {
        return 'va-membership-portal-v1-'.str_repeat('e', 64);
    }

    private function routes(): array
    {
        return [
            'GET /v1/account' => ['id' => F::ACCOUNT, 'object' => 'account'],
            'POST /v1/checkout/sessions' => ['id' => 'cs_test_SYNTHETICWRITE', 'object' => 'checkout.session', 'livemode' => false, 'mode' => 'subscription',
                'status' => 'open', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_SYNTHETICWRITE', 'client_reference_id' => 'va-sub-'.str_repeat('c', 32),
                'customer' => null, 'currency' => 'usd', 'amount_total' => 1700, 'expires_at' => 1893456000, 'account' => null, 'context' => null,
                'metadata' => $this->params()['metadata']],
            'POST /v1/billing_portal/sessions' => ['id' => 'bps_SYNTHETICWRITE', 'object' => 'billing_portal.session', 'livemode' => false,
                'customer' => F::CUSTOMER, 'url' => 'https://billing.stripe.com/p/session/test_SYNTHETICWRITE', 'return_url' => 'http://localhost/membership',
                'configuration' => 'bpc_SYNTHETIC'],
            'GET /v1/prices/'.F::PRICE => ['id' => F::PRICE, 'object' => 'price', 'livemode' => false, 'active' => true, 'currency' => 'usd', 'unit_amount' => 1700,
                'type' => 'recurring', 'billing_scheme' => 'per_unit', 'tiers_mode' => null, 'custom_unit_amount' => null, 'transform_quantity' => null,
                'recurring' => ['interval' => 'month', 'interval_count' => 1, 'usage_type' => 'licensed']],
        ];
    }
}
