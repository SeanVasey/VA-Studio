<?php

namespace Tests\Unit;

use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Tests\TestCase;
use Throwable;

class StripePaymentGatewayTest extends TestCase
{
    private ClientInterface $previousTransport;

    private array $previousGlobals;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'payments.stripe.mode' => 'test',
            'payments.stripe.account_id' => 'acct_synthetic',
            'payments.stripe.secret_key' => 'sk_test_syntheticCredential123456',
        ]);
        $this->previousTransport = ApiRequestor::httpClient();
        $this->previousGlobals = [Stripe::$accountId, Stripe::$apiVersion, Stripe::$verifySslCerts, Stripe::$maxNetworkRetries];
        Stripe::$accountId = null;
        Stripe::$verifySslCerts = true;
    }

    protected function tearDown(): void
    {
        $this->app->instance('env', 'testing');
        ApiRequestor::setHttpClient($this->previousTransport);
        [Stripe::$accountId, Stripe::$apiVersion, Stripe::$verifySslCerts, Stripe::$maxNetworkRetries] = $this->previousGlobals;
        parent::tearDown();
    }

    public function test_both_interfaces_resolve_to_the_guarded_sdk_adapter(): void
    {
        $this->assertInstanceOf(StripeSdkCheckoutGateway::class, app(StripePaymentGateway::class));
        $this->assertInstanceOf(StripeCheckoutGateway::class, app(StripePaymentGateway::class));
        $this->assertInstanceOf(StripePaymentGateway::class, app(StripeCheckoutGateway::class));
    }

    public function test_payment_retrieval_is_get_only_with_own_account_pinned_version_and_no_retries(): void
    {
        Stripe::$apiVersion = '2000-01-01';
        Stripe::$maxNetworkRetries = 9;
        $http = new PaymentHttpFixture([
            $this->providerAccountFixture(), $this->providerPaymentFixture(),
            $this->providerAccountFixture(), $this->providerPaymentFixture(),
        ]);
        $gateway = new StripeSdkCheckoutGateway($http);

        $payment = $gateway->paymentIntent('pi_synthetic');
        $this->assertSame('pi_synthetic', $payment['id']);
        $this->assertSame('automatic_async', $payment['capture_method']);
        $this->assertArrayNotHasKey('client_secret', $payment);
        $this->assertSame($payment, $gateway->paymentIntent('pi_synthetic'));

        $this->assertCount(4, $http->requests);
        foreach ($http->requests as $index => $request) {
            $this->assertSame('get', $request['method']);
            $this->assertSame('https://api.stripe.com/v1/'.($index % 2 === 0 ? 'account' : 'payment_intents/pi_synthetic'), $request['url']);
            $this->assertSame([], $request['params']);
            $this->assertSame('v1', $request['apiMode']);
            $this->assertSame(0, $request['retries']);
            $this->assertFalse($request['hasFile']);
            $this->assertContains('Authorization: Bearer sk_test_syntheticCredential123456', $request['headers']);
            $this->assertContains('Stripe-Version: 2026-08-26.dahlia', $request['headers']);
            $this->assertSame([], array_values(array_filter($request['headers'], fn (string $header): bool => str_starts_with($header, 'Stripe-Account:') || str_starts_with($header, 'Stripe-Context:') || str_starts_with($header, 'Idempotency-Key:'))));
        }
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertSame('2000-01-01', Stripe::$apiVersion);
        $this->assertSame(9, Stripe::$maxNetworkRetries);
    }

    public static function malformedPaymentLocators(): array
    {
        return [
            [''], ['pi_'], ['../account'], ['cs_test_synthetic'], ['pi_has_underscore'],
            ['pi_other?expand[]=customer'], ['pi_other/confirm'], ['pi_other#fragment'],
            ["pi_other\r\nHeader: value"], ["pi_other\0"], ['pi_é'], ['pi_'.str_repeat('a', 241)],
        ];
    }

    #[DataProvider('malformedPaymentLocators')]
    public function test_malformed_payment_locators_never_reach_the_transport(string $locator): void
    {
        $http = new PaymentHttpFixture([]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent($locator));
        $this->assertSame([], $http->requests);
    }

    public function test_bounded_payment_locator_is_preserved_exactly_in_the_get_path(): void
    {
        $locator = 'pi_'.str_repeat('A', 240);
        $payment = $this->providerPaymentFixture();
        $payment['id'] = $locator;
        $http = new PaymentHttpFixture([$this->providerAccountFixture(), $payment]);
        $this->assertSame($locator, (new StripeSdkCheckoutGateway($http))->paymentIntent($locator)['id']);
        $this->assertSame('https://api.stripe.com/v1/payment_intents/'.$locator, $http->requests[1]['url']);
    }

    public static function unsafePaymentConfiguration(): array
    {
        return [
            ['mode', 'live'], ['mode', null], ['account_id', null], ['account_id', 'acct_../other'],
            ['secret_key', null], ['secret_key', 'sk_live_syntheticCredential123456'],
            ['secret_key', 'rk_test_syntheticCredential123456'], ['secret_key', "sk_test_bad\nheader"],
        ];
    }

    #[DataProvider('unsafePaymentConfiguration')]
    public function test_unsafe_payment_configuration_never_reaches_the_transport(string $key, mixed $value): void
    {
        config(['payments.stripe.'.$key => $value]);
        $http = new PaymentHttpFixture([]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertSame([], $http->requests);
    }

    public static function nonDevelopmentPaymentEnvironments(): array
    {
        return [['production'], ['preview']];
    }

    #[DataProvider('nonDevelopmentPaymentEnvironments')]
    public function test_payment_retrieval_is_unavailable_outside_local_or_testing(string $environment): void
    {
        $this->app->instance('env', $environment);
        $http = new PaymentHttpFixture([]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertSame([], $http->requests);
    }

    public function test_payment_retrieval_rejects_global_connect_routing_and_disabled_tls(): void
    {
        $http = new PaymentHttpFixture([]);
        $gateway = new StripeSdkCheckoutGateway($http);
        Stripe::$accountId = 'acct_other';
        $this->assertPaymentUnavailable(fn () => $gateway->paymentIntent('pi_synthetic'));
        Stripe::$accountId = null;
        Stripe::$verifySslCerts = false;
        $this->assertPaymentUnavailable(fn () => $gateway->paymentIntent('pi_synthetic'));
        $this->assertSame([], $http->requests);
    }

    public function test_a_transaction_on_a_secondary_connection_prevents_payment_network_io(): void
    {
        $primary = Mockery::mock(Connection::class);
        $primary->shouldReceive('transactionLevel')->andReturn(0);
        $secondary = Mockery::mock(Connection::class);
        $secondary->shouldReceive('transactionLevel')->andReturn(1);
        DB::shouldReceive('getConnections')->andReturn(['primary' => $primary, 'secondary' => $secondary]);
        $http = new PaymentHttpFixture([]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertSame([], $http->requests);
    }

    public static function wrongCredentialOwnerEvidence(): array
    {
        return [[['id' => 'acct_other', 'object' => 'account']], [['id' => 'acct_synthetic', 'object' => 'customer']]];
    }

    #[DataProvider('wrongCredentialOwnerEvidence')]
    public function test_credential_owner_mismatch_stops_before_payment_retrieval(array $account): void
    {
        $http = new PaymentHttpFixture([$account]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertCount(1, $http->requests);
        $this->assertSame('https://api.stripe.com/v1/account', $http->requests[0]['url']);
    }

    public static function unsafePaymentEvidence(): array
    {
        return [
            ['id', 'pi_other'], ['id', null], ['object', 'charge'], ['object', null],
            ['livemode', true], ['livemode', null], ['livemode', 0],
            ['account', 'acct_synthetic'], ['context', 'acct_synthetic'],
            ['application', 'ca_synthetic'], ['application_fee_amount', 0],
            ['on_behalf_of', 'acct_other'], ['transfer_data', ['destination' => 'acct_other']],
            ['transfer_group', 'synthetic'],
        ];
    }

    #[DataProvider('unsafePaymentEvidence')]
    public function test_wrong_payment_identity_mode_or_connect_evidence_is_rejected(string $key, mixed $value): void
    {
        $payment = $this->providerPaymentFixture();
        $payment[$key] = $value;
        $http = new PaymentHttpFixture([$this->providerAccountFixture(), $payment]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertCount(2, $http->requests);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_payment_transport_exception_is_sanitized_without_retaining_the_cause(): void
    {
        $http = new PaymentHttpFixture([
            $this->providerAccountFixture(),
            new ApiConnectionException('private@example.test sk_test_syntheticCredential123456 pi_synthetic_secret_private'),
        ]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertCount(2, $http->requests);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_payment_sdk_error_payload_is_sanitized_and_transport_is_restored(): void
    {
        $http = new PaymentHttpFixture([
            $this->providerAccountFixture(),
            ['_http_status' => 400, 'error' => ['type' => 'invalid_request_error', 'message' => 'private@example.test sk_test_syntheticCredential123456 pi_synthetic_secret_private']],
        ]);
        $this->assertPaymentUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->paymentIntent('pi_synthetic'));
        $this->assertCount(2, $http->requests);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    private function assertPaymentUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe payment retrieval was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('STRIPE_CHECKOUT_UNAVAILABLE', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            foreach (['private@example.test', 'sk_test_syntheticCredential123456', 'pi_synthetic_secret_private'] as $private) {
                $this->assertStringNotContainsString($private, (string) $exception);
            }
        }
    }

    private function providerAccountFixture(): array
    {
        return ['id' => 'acct_synthetic', 'object' => 'account'];
    }

    private function providerPaymentFixture(): array
    {
        return [
            'id' => 'pi_synthetic', 'object' => 'payment_intent', 'livemode' => false,
            'amount' => 1200, 'amount_received' => 1200, 'amount_capturable' => 0,
            'currency' => 'usd', 'status' => 'succeeded', 'capture_method' => 'automatic_async',
            'client_secret' => 'pi_synthetic_secret_private',
            'application' => null, 'application_fee_amount' => null, 'on_behalf_of' => null,
            'transfer_data' => null, 'transfer_group' => null,
        ];
    }
}

final class PaymentHttpFixture implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'hasFile' => $hasFile, 'apiMode' => $apiMode, 'retries' => $maxNetworkRetries];
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected synthetic payment HTTP request.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        $status = $response['_http_status'] ?? 200;
        unset($response['_http_status']);

        return [json_encode($response, JSON_THROW_ON_ERROR), $status, ['request-id' => 'req_synthetic']];
    }
}
