<?php

namespace Tests\Unit;

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

class StripeCheckoutGatewayTest extends TestCase
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
        ApiRequestor::setHttpClient($this->previousTransport);
        [Stripe::$accountId, Stripe::$apiVersion, Stripe::$verifySslCerts, Stripe::$maxNetworkRetries] = $this->previousGlobals;
        parent::tearDown();
    }

    public function test_create_uses_own_account_official_host_pinned_version_and_exact_key_without_retries(): void
    {
        Stripe::$apiVersion = '2000-01-01';
        Stripe::$maxNetworkRetries = 9;
        $http = new CheckoutHttpFixture([$this->account(), $this->session(), $this->account(), $this->session()]);
        $gateway = new StripeSdkCheckoutGateway($http);
        $params = ['mode' => 'payment', 'expand' => ['line_items'], 'metadata' => ['order' => 'synthetic']];

        $this->assertSame('cs_test_synthetic', $gateway->create($params, 'order:synthetic-v1')['id']);
        $gateway->create($params, 'order:synthetic-v1');

        $this->assertCount(4, $http->requests);
        foreach ($http->requests as $index => $request) {
            $isCreate = $index % 2 === 1;
            $this->assertSame($isCreate ? 'post' : 'get', $request['method']);
            $this->assertSame('https://api.stripe.com/v1/'.($isCreate ? 'checkout/sessions' : 'account'), $request['url']);
            $this->assertSame('v1', $request['apiMode']);
            $this->assertSame(0, $request['retries']);
            $this->assertFalse($request['hasFile']);
            $this->assertContains('Authorization: Bearer sk_test_syntheticCredential123456', $request['headers']);
            $this->assertContains('Stripe-Version: 2026-08-26.dahlia', $request['headers']);
            $this->assertSame([], array_values(array_filter($request['headers'], fn (string $header): bool => str_starts_with($header, 'Stripe-Account:') || str_starts_with($header, 'Stripe-Context:'))));
            if ($isCreate) {
                $this->assertSame($params, $request['params']);
                $this->assertContains('Idempotency-Key: order:synthetic-v1', $request['headers']);
            } else {
                $this->assertSame([], $request['params']);
                $this->assertSame([], array_values(array_filter($request['headers'], fn (string $header): bool => str_starts_with($header, 'Idempotency-Key:'))));
            }
        }
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
        $this->assertSame('2000-01-01', Stripe::$apiVersion);
        $this->assertSame(9, Stripe::$maxNetworkRetries);
    }

    public function test_account_reads_the_credential_owner_and_returns_only_identity(): void
    {
        $http = new CheckoutHttpFixture([$this->account() + ['email' => 'private@example.test']]);
        $this->assertSame($this->account(), (new StripeSdkCheckoutGateway($http))->account());
        $this->assertSame('https://api.stripe.com/v1/account', $http->requests[0]['url']);
        $this->assertCount(1, $http->requests);
    }

    public function test_retrieve_expands_items_and_never_uses_a_creation_key(): void
    {
        $http = new CheckoutHttpFixture([$this->account(), $this->session()]);
        $session = (new StripeSdkCheckoutGateway($http))->retrieve('cs_test_synthetic');
        $this->assertSame('cs_test_synthetic', $session['id']);
        $this->assertCount(2, $http->requests);
        $this->assertSame('get', $http->requests[1]['method']);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_synthetic', $http->requests[1]['url']);
        $this->assertSame(['expand' => ['line_items']], $http->requests[1]['params']);
        $this->assertSame([], array_values(array_filter($http->requests[1]['headers'], fn (string $header): bool => str_starts_with($header, 'Idempotency-Key:'))));
    }

    public static function operations(): array
    {
        return [['create'], ['retrieve']];
    }

    #[DataProvider('operations')]
    public function test_partial_expansion_uses_one_bounded_complete_list(string $operation): void
    {
        $partial = $this->session();
        $partial['line_items']['has_more'] = true;
        $all = ['object' => 'list', 'has_more' => false, 'data' => [['id' => 'li_synthetic', 'object' => 'item']]];
        $http = new CheckoutHttpFixture([$this->account(), $partial, $all]);
        $result = $this->invoke(new StripeSdkCheckoutGateway($http), $operation);
        $this->assertSame($all, $result['line_items']);
        $this->assertCount(3, $http->requests);
        $this->assertSame('https://api.stripe.com/v1/checkout/sessions/cs_test_synthetic/line_items', $http->requests[2]['url']);
        $this->assertSame(['limit' => 100], $http->requests[2]['params']);
    }

    public function test_more_than_one_page_fails_without_an_unbounded_pagination_loop(): void
    {
        $partial = $this->session();
        $partial['line_items']['has_more'] = true;
        $http = new CheckoutHttpFixture([$this->account(), $partial, $partial['line_items']]);
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->retrieve('cs_test_synthetic'));
        $this->assertCount(3, $http->requests);
    }

    public static function invalidConfiguration(): array
    {
        return [
            ['mode', 'live'], ['mode', null], ['account_id', null], ['account_id', 'acct_../other'],
            ['secret_key', null], ['secret_key', 'sk_live_syntheticCredential123456'],
            ['secret_key', 'rk_test_syntheticCredential123456'], ['secret_key', "sk_test_bad\nheader"],
        ];
    }

    #[DataProvider('invalidConfiguration')]
    public function test_invalid_or_live_configuration_never_reaches_the_transport(string $field, mixed $value): void
    {
        config(['payments.stripe.'.$field => $value]);
        $http = new CheckoutHttpFixture([]);
        $gateway = new StripeSdkCheckoutGateway($http);
        foreach (['account', 'create', 'retrieve'] as $operation) {
            $this->assertUnavailable(fn () => $this->invoke($gateway, $operation));
        }
        $this->assertSame([], $http->requests);
    }

    public static function unavailableEnvironments(): array
    {
        return [['production'], ['staging']];
    }

    #[DataProvider('unavailableEnvironments')]
    public function test_non_development_environments_never_reach_the_transport(string $environment): void
    {
        $this->app->instance('env', $environment);
        $http = new CheckoutHttpFixture([]);
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->account());
        $this->assertSame([], $http->requests);
    }

    public function test_global_connect_account_or_disabled_tls_verification_fails_before_io(): void
    {
        $http = new CheckoutHttpFixture([]);
        Stripe::$accountId = 'acct_other';
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->account());
        Stripe::$accountId = null;
        Stripe::$verifySslCerts = false;
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->account());
        $this->assertSame([], $http->requests);
    }

    public function test_a_transaction_on_any_open_connection_blocks_all_provider_io(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('transactionLevel')->andReturn(1);
        DB::shouldReceive('getConnections')->andReturn(['secondary' => $connection]);
        $http = new CheckoutHttpFixture([]);
        $gateway = new StripeSdkCheckoutGateway($http);
        foreach (['account', 'create', 'retrieve'] as $operation) {
            $this->assertUnavailable(fn () => $this->invoke($gateway, $operation));
        }
        $this->assertSame([], $http->requests);
    }

    #[DataProvider('operations')]
    public function test_account_mismatch_prevents_a_session_request(string $operation): void
    {
        $http = new CheckoutHttpFixture([['id' => 'acct_wrong', 'object' => 'account']]);
        $this->assertUnavailable(fn () => $this->invoke(new StripeSdkCheckoutGateway($http), $operation));
        $this->assertCount(1, $http->requests);
    }

    public static function invalidSessionEvidence(): array
    {
        return [
            ['id', 'cs_live_other'], ['id', 'cs_test_different'], ['livemode', true], ['livemode', null],
            ['object', 'payment_intent'], ['account', 'acct_other'], ['context', 'acct_other'],
            ['line_items', null], ['line_items', ['object' => 'list', 'has_more' => false, 'data' => 'invalid']],
        ];
    }

    #[DataProvider('invalidSessionEvidence')]
    public function test_wrong_session_identity_mode_or_incomplete_items_fail_closed(string $key, mixed $value): void
    {
        $session = $this->session();
        $session[$key] = $value;
        $http = new CheckoutHttpFixture([$this->account(), $session]);
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->retrieve('cs_test_synthetic'));
        $this->assertCount(2, $http->requests);
    }

    public function test_invalid_ids_keys_and_connect_routing_are_rejected_without_io(): void
    {
        $http = new CheckoutHttpFixture([]);
        $gateway = new StripeSdkCheckoutGateway($http);
        foreach (['cs_live_other', '../account', 'cs_test_a?expand[]=customer', ''] as $id) {
            $this->assertUnavailable(fn () => $gateway->retrieve($id));
        }
        foreach (['', "key\r\nHeader: value", str_repeat('x', 256)] as $key) {
            $this->assertUnavailable(fn () => $gateway->create(['mode' => 'payment'], $key));
        }
        foreach (['application_fee_amount', 'on_behalf_of', 'transfer_data'] as $key) {
            $this->assertUnavailable(fn () => $gateway->create(['mode' => 'payment', 'payment_intent_data' => [$key => 'synthetic']], 'synthetic'));
        }
        $this->assertSame([], $http->requests);
    }

    public function test_provider_failure_is_sanitized_and_the_previous_transport_is_restored(): void
    {
        $http = new CheckoutHttpFixture([$this->account(), new ApiConnectionException('private@example.test sk_test_syntheticCredential123456 https://checkout.stripe.com/private')]);
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->create([
            'mode' => 'payment', 'customer_email' => 'private@example.test',
        ], 'synthetic'));
        $this->assertCount(2, $http->requests);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    public function test_sdk_http_errors_do_not_retain_provider_messages_or_payloads(): void
    {
        $http = new CheckoutHttpFixture([[
            '_http_status' => 400,
            'error' => ['type' => 'invalid_request_error', 'message' => 'private@example.test sk_test_syntheticCredential123456'],
        ]]);
        $this->assertUnavailable(fn () => (new StripeSdkCheckoutGateway($http))->account());
        $this->assertCount(1, $http->requests);
        $this->assertSame($this->previousTransport, ApiRequestor::httpClient());
    }

    private function invoke(StripeSdkCheckoutGateway $gateway, string $operation): array
    {
        return match ($operation) {
            'account' => $gateway->account(),
            'create' => $gateway->create(['mode' => 'payment', 'expand' => ['line_items']], 'synthetic'),
            'retrieve' => $gateway->retrieve('cs_test_synthetic'),
        };
    }

    private function assertUnavailable(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe provider request was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('STRIPE_CHECKOUT_UNAVAILABLE', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
            $this->assertStringNotContainsString('private@example.test', (string) $exception);
            $this->assertStringNotContainsString('sk_test_syntheticCredential123456', (string) $exception);
        }
    }

    private function account(): array
    {
        return ['id' => 'acct_synthetic', 'object' => 'account'];
    }

    private function session(): array
    {
        return [
            'id' => 'cs_test_synthetic', 'object' => 'checkout.session', 'livemode' => false,
            'line_items' => ['object' => 'list', 'data' => [], 'has_more' => false],
        ];
    }
}

final class CheckoutHttpFixture implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'hasFile' => $hasFile, 'apiMode' => $apiMode, 'retries' => $maxNetworkRetries];
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected synthetic HTTP request.');
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
