<?php

namespace Tests\Unit;

use App\Domain\Services\Invoicing\StripeSdkInvoiceGateway;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\Exception\ApiConnectionException;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Tests\TestCase;
use Throwable;

/** The service invoice gateway against a recording transport: pinned version, own account, test keys only, no retries, no leaks. */
class StripeInvoiceGatewayTest extends TestCase
{
    private const KEY = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    private ClientInterface $previousTransport;

    private array $previousGlobals;

    private string $secret;

    protected function setUp(): void
    {
        parent::setUp();
        $this->secret = 'sk_'.'test_syntheticCredential123456';
        config(['payments.stripe.mode' => 'test', 'payments.stripe.account_id' => 'acct_synthetic', 'payments.stripe.secret_key' => $this->secret]);
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

    private function spec(array $changes = []): array
    {
        return array_replace(['customer_id' => null, 'email' => 'buyer@example.invalid', 'amount_minor' => 3500, 'currency' => 'USD', 'days_until_due' => 14,
            'description' => 'Deposit: Synthetic scope', 'invoice_ref' => '2f9d2e0e-3d0e-4c5a-9a3e-1b2c3d4e5f60', 'project_ref' => '6e1d7f0a-0c53-4b43-a0c0-6e6c4f3b9a11', 'key' => self::KEY], $changes);
    }

    private function account(string $id = 'acct_synthetic'): array
    {
        return ['id' => $id, 'object' => 'account'];
    }

    private function invoice(array $changes = []): array
    {
        return array_replace(['id' => 'in_SyntheticOne', 'object' => 'invoice', 'livemode' => false, 'status' => 'draft', 'customer' => 'cus_SyntheticOne',
            'currency' => 'usd', 'total' => 0, 'amount_due' => 0, 'lines' => ['object' => 'list', 'data' => [], 'has_more' => false]], $changes);
    }

    private function none(): array
    {
        return ['object' => 'search_result', 'data' => [], 'has_more' => false];
    }

    private function line(): array
    {
        return ['id' => 'il_Synthetic', 'object' => 'line_item', 'amount' => 3500];
    }

    private function open(): array
    {
        return $this->invoice(['status' => 'open', 'total' => 3500, 'amount_due' => 3500, 'lines' => ['object' => 'list', 'data' => [$this->line()], 'has_more' => false]]);
    }

    public function test_issue_creates_customer_draft_line_and_finalizes_with_pinned_account_version_keys_and_no_retries(): void
    {
        Stripe::$apiVersion = '2000-01-01';
        Stripe::$maxNetworkRetries = 9;
        $http = new InvoiceHttpFixture([$this->account(), $this->none(), ['id' => 'cus_SyntheticOne', 'object' => 'customer', 'livemode' => false], $this->invoice(), $this->invoice(), $this->open(), $this->open()]);
        $result = (new StripeSdkInvoiceGateway($http))->issue($this->spec());

        $this->assertSame('cus_SyntheticOne', $result['customer_id']);
        $this->assertSame('open', $result['invoice']['status']);
        $expected = [['get', 'account'], ['get', 'invoices/search'], ['post', 'customers'], ['post', 'invoices'], ['get', 'invoices/in_SyntheticOne'],
            ['post', 'invoices/in_SyntheticOne/add_lines'], ['post', 'invoices/in_SyntheticOne/finalize']];
        $this->assertSame($expected, array_map(fn (array $request): array => [$request['method'], substr($request['url'], strlen('https://api.stripe.com/v1/'))], $http->requests));
        foreach ($http->requests as $request) {
            $this->assertSame(0, $request['retries']);
            $this->assertContains('Authorization: Bearer '.$this->secret, $request['headers']);
            $this->assertContains('Stripe-Version: 2026-08-26.dahlia', $request['headers']);
            $this->assertSame([], array_values(array_filter($request['headers'], fn (string $header): bool => str_starts_with($header, 'Stripe-Account:') || str_starts_with($header, 'Stripe-Context:'))));
        }
        $this->assertContains('Idempotency-Key: svcinv-cus-'.self::KEY, $http->requests[2]['headers']);
        $this->assertContains('Idempotency-Key: svcinv-inv-'.self::KEY, $http->requests[3]['headers']);
        $this->assertContains('Idempotency-Key: svcinv-line-'.self::KEY, $http->requests[5]['headers']);
        $this->assertContains('Idempotency-Key: svcinv-fin-'.self::KEY, $http->requests[6]['headers']);
        $this->assertSame(['email' => 'buyer@example.invalid', 'metadata' => ['va_project' => '6e1d7f0a-0c53-4b43-a0c0-6e6c4f3b9a11']], $http->requests[2]['params']);
        $this->assertSame(['customer' => 'cus_SyntheticOne', 'collection_method' => 'send_invoice', 'days_until_due' => 14, 'currency' => 'usd', 'auto_advance' => 'false',
            'pending_invoice_items_behavior' => 'exclude', 'description' => 'Deposit: Synthetic scope',
            'metadata' => ['va_service_invoice' => '2f9d2e0e-3d0e-4c5a-9a3e-1b2c3d4e5f60', 'va_project' => '6e1d7f0a-0c53-4b43-a0c0-6e6c4f3b9a11']], $http->requests[3]['params']);
        $this->assertSame(['lines' => [['description' => 'Deposit: Synthetic scope', 'amount' => 3500]]], $http->requests[5]['params']);
        $this->assertStringContainsString('va_service_invoice', (string) json_encode($http->requests[1]['params']));
    }

    public function test_a_resumed_issue_reuses_the_customer_and_skips_steps_the_provider_already_completed(): void
    {
        $http = new InvoiceHttpFixture([$this->account(), $this->none(), $this->open(), $this->open()]);
        $result = (new StripeSdkInvoiceGateway($http))->issue($this->spec(['customer_id' => 'cus_SyntheticOne']));
        $this->assertSame('open', $result['invoice']['status']);
        $this->assertSame(['account', 'invoices/search', 'invoices', 'invoices/in_SyntheticOne'], array_map(fn (array $request): string => substr($request['url'], strlen('https://api.stripe.com/v1/')), $http->requests));

        $withLine = $this->invoice(['lines' => ['object' => 'list', 'data' => [$this->line()], 'has_more' => false]]);
        $http = new InvoiceHttpFixture([$this->account(), $this->none(), $withLine, $withLine, $this->open()]);
        (new StripeSdkInvoiceGateway($http))->issue($this->spec(['customer_id' => 'cus_SyntheticOne']));
        $this->assertSame(['account', 'invoices/search', 'invoices', 'invoices/in_SyntheticOne', 'invoices/in_SyntheticOne/finalize'],
            array_map(fn (array $request): string => substr($request['url'], strlen('https://api.stripe.com/v1/')), $http->requests));
    }

    public function test_an_invoice_that_already_carries_the_local_reference_is_adopted_not_duplicated(): void
    {
        $ref = '2f9d2e0e-3d0e-4c5a-9a3e-1b2c3d4e5f60';
        $existing = $this->open() + [];
        $existing['metadata'] = ['va_service_invoice' => $ref];
        $http = new InvoiceHttpFixture([$this->account(), ['object' => 'search_result', 'data' => [$existing], 'has_more' => false], $existing]);
        $result = (new StripeSdkInvoiceGateway($http))->issue($this->spec());
        $this->assertSame(['in_SyntheticOne', 'cus_SyntheticOne'], [$result['invoice']['id'], $result['customer_id']]);
        $this->assertSame(['account', 'invoices/search', 'invoices/in_SyntheticOne'],
            array_map(fn (array $request): string => substr($request['url'], strlen('https://api.stripe.com/v1/')), $http->requests));
        $this->assertSame([], array_filter($http->requests, fn (array $request): bool => $request['method'] === 'post'));
        // Two invoices naming one local row is ambiguous: refuse rather than choose.
        $http = new InvoiceHttpFixture([$this->account(), ['object' => 'search_result', 'data' => [$existing, [...$existing, 'id' => 'in_Second']], 'has_more' => false]]);
        $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->issue($this->spec()));
        // A reference that is not a UUID is never interpolated into a search query.
        $http = new InvoiceHttpFixture([$this->account()]);
        $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->issue($this->spec(['invoice_ref' => "x' OR metadata['a']:'b"])));
    }

    public static function refusedSpecs(): array
    {
        return [
            'euro' => [['currency' => 'EUR']], 'zero amount' => [['amount_minor' => 0]], 'float amount' => [['amount_minor' => 35.0]],
            'huge amount' => [['amount_minor' => 2147483648]], 'no due days' => [['days_until_due' => 0]], 'too many due days' => [['days_until_due' => 91]],
            'short key' => [['key' => 'abc']], 'upper key' => [['key' => str_repeat('A', 64)]], 'empty email' => [['email' => '']],
            'foreign customer id' => [['customer_id' => 'acct_NotACustomer']],
        ];
    }

    #[DataProvider('refusedSpecs')]
    public function test_malformed_issue_requests_are_refused_before_any_provider_request(array $changes): void
    {
        $http = new InvoiceHttpFixture([]);
        $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->issue($this->spec($changes)));
        $this->assertSame([], $http->requests);
    }

    public function test_only_an_admitted_environment_test_mode_and_an_exact_test_key_reach_the_provider(): void
    {
        $http = new InvoiceHttpFixture([]);
        $gateway = new StripeSdkInvoiceGateway($http);
        foreach (['sk_'.'live_syntheticCredential123456', 'rk_'.'test_syntheticCredential123456', 'pk_'.'test_syntheticCredential123456', 'sk_'.'test_short', null] as $key) {
            config(['payments.stripe.secret_key' => $key]);
            $this->refused(fn () => $gateway->issue($this->spec()));
            $this->refused(fn () => $gateway->retrieve('in_SyntheticOne'));
        }
        config(['payments.stripe.secret_key' => $this->secret]);
        foreach (['live', null, 'TEST'] as $mode) {
            config(['payments.stripe.mode' => $mode]);
            $this->refused(fn () => $gateway->retrieve('in_SyntheticOne'));
        }
        config(['payments.stripe.mode' => 'test']);
        foreach (['production', 'preview', 'Staging'] as $environment) {
            $this->app->detectEnvironment(fn () => $environment);
            $this->refused(fn () => $gateway->retrieve('in_SyntheticOne'));
        }
        $this->app->detectEnvironment(fn () => 'testing');
        config(['payments.stripe.account_id' => 'not-an-account']);
        $this->refused(fn () => $gateway->retrieve('in_SyntheticOne'));
        $this->assertSame([], $http->requests);
    }

    public function test_no_provider_request_is_made_inside_an_application_transaction(): void
    {
        $http = new InvoiceHttpFixture([]);
        DB::beginTransaction();
        try {
            $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->retrieve('in_SyntheticOne'));
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $http->requests);
    }

    public function test_a_credential_for_another_account_stops_before_any_invoice_request(): void
    {
        $http = new InvoiceHttpFixture([$this->account('acct_someone_else')]);
        $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->retrieve('in_SyntheticOne'));
        $this->assertCount(1, $http->requests);
        $this->assertSame('https://api.stripe.com/v1/account', $http->requests[0]['url']);
    }

    public static function foreignInvoices(): array
    {
        return [
            'live mode' => [['livemode' => true]], 'other id' => [['id' => 'in_Other']], 'connected account' => [['account' => 'acct_Other']],
            'context' => [['context' => 'ctx_Other']], 'application' => [['application' => 'ca_Other']], 'wrong object' => [['object' => 'quote']],
        ];
    }

    #[DataProvider('foreignInvoices')]
    public function test_a_retrieved_object_must_be_this_own_account_test_mode_invoice(array $changes): void
    {
        $http = new InvoiceHttpFixture([$this->account(), $this->invoice($changes)]);
        $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->retrieve('in_SyntheticOne'));
        $http = new InvoiceHttpFixture([$this->account(), $this->invoice()]);
        $this->assertSame('in_SyntheticOne', (new StripeSdkInvoiceGateway($http))->retrieve('in_SyntheticOne')['id']);
    }

    public function test_malformed_invoice_ids_are_refused_without_a_request(): void
    {
        $http = new InvoiceHttpFixture([]);
        foreach (['', 'in_', 'cs_test_x', 'in_x/../account', 'in_'.str_repeat('a', 250)] as $id) {
            $this->refused(fn () => (new StripeSdkInvoiceGateway($http))->retrieve($id));
        }
        $this->assertSame([], $http->requests);
    }

    public function test_provider_failures_surface_only_the_constant_message(): void
    {
        $http = new InvoiceHttpFixture([$this->account(), new ApiConnectionException('boom '.$this->secret.' buyer@example.invalid')]);
        try {
            (new StripeSdkInvoiceGateway($http))->retrieve('in_SyntheticOne');
            $this->fail('A transport failure was swallowed.');
        } catch (RuntimeException $error) {
            $this->assertSame('STRIPE_INVOICE_UNAVAILABLE', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString($this->secret, $error->getTraceAsString());
        }
    }

    private function refused(callable $operation): void
    {
        try {
            $operation();
        } catch (RuntimeException $error) {
            $this->assertSame('STRIPE_INVOICE_UNAVAILABLE', $error->getMessage());

            return;
        }
        $this->fail('The gateway reached the provider.');
    }
}

final class InvoiceHttpFixture implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'retries' => $maxNetworkRetries];
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected synthetic HTTP request.');
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_synthetic']];
    }
}
