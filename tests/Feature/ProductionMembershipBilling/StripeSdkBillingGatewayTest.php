<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingProviderPin;
use App\Domain\Memberships\Billing\BillingReconciliation;
use App\Domain\Memberships\Billing\BillingValues;
use App\Domain\Memberships\Billing\StripeSdkBillingGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\BillingHttpFixture;
use Tests\Support\BillingStripeFixtures as F;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\TestCase;

/** The real SDK gateway over a loopback transport: GET only, pinned wire version, bounded lists, redacted errors. */
class StripeSdkBillingGatewayTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestampUTC(F::PERIOD_START + 3600));
        F::configure(['provider_io_enabled' => true]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_complete_graph_settles_through_the_sdk_with_get_requests_only(): void
    {
        $binding = F::binding();
        $transport = new BillingHttpFixture($this->routes(F::graph()));
        $observation = (new BillingReconciliation(new StripeSdkBillingGateway($transport)))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame('settled', $observation['outcome']);
        $this->assertSame(['/v1/account', '/v1/invoices/'.F::INVOICE, '/v1/invoice_payments', '/v1/payment_intents/'.F::PAYMENT_INTENT,
            '/v1/charges/'.F::CHARGE, '/v1/balance_transactions/'.F::BALANCE_TRANSACTION, '/v1/subscriptions/'.F::SUBSCRIPTION],
            array_map(fn (array $call) => parse_url($call['url'], PHP_URL_PATH), $transport->calls));
        foreach ($transport->calls as $call) {
            $this->assertSame('get', strtolower($call['method']));
            $this->assertContains('Stripe-Version: '.BillingProviderPin::API_VERSION, $call['headers']);
        }
        $retained = json_encode(BillingValues::decrypt($observation['payload_ciphertext']));
        $this->assertStringNotContainsString('secret', $retained);
        $this->assertStringNotContainsString('example.invalid', $retained);
    }

    public function test_first_page_line_list_is_completed_and_an_unbounded_payment_list_is_refused(): void
    {
        $binding = F::binding();
        $graph = F::graph();
        $routes = $this->routes($graph);
        $page = $graph['invoice']['lines'];
        $routes['/v1/invoices/'.F::INVOICE]['lines'] = [...$page, 'has_more' => true];
        $routes['/v1/invoices/'.F::INVOICE.'/lines?limit=100'] = $page;
        $transport = new BillingHttpFixture($routes);
        $this->assertSame('settled', (new BillingReconciliation(new StripeSdkBillingGateway($transport)))->retrieve($binding['id'], F::INVOICE)['outcome']);
        $routes['/v1/invoice_payments?invoice='.F::INVOICE.'&limit=10']['has_more'] = true;
        $refused = (new BillingReconciliation(new StripeSdkBillingGateway(new BillingHttpFixture($routes))))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame(['refused', 'provider_incomplete'], [$refused['outcome'], BillingValues::decrypt($refused['payload_ciphertext'])['reason']]);
    }

    public function test_transport_failure_is_redacted_into_an_unknown_observation(): void
    {
        $binding = F::binding();
        $routes = $this->routes(F::graph());
        $routes['/v1/charges/'.F::CHARGE] = new RuntimeException('PRIVATE URL HEADER sk_test_SYNTHETICREHEARSAL BODY');
        $observation = (new BillingReconciliation(new StripeSdkBillingGateway(new BillingHttpFixture($routes))))->retrieve($binding['id'], F::INVOICE);
        $this->assertSame('unknown', $observation['outcome']);
        $payload = BillingValues::decrypt($observation['payload_ciphertext']);
        $this->assertSame('provider_unavailable', $payload['reason']);
        $this->assertStringNotContainsString('PRIVATE', json_encode($payload));
    }

    public function test_configuration_transport_and_transaction_refusals_happen_before_any_request(): void
    {
        $cases = [
            'provider_io_disabled' => fn () => config(['production-membership-billing.provider_io_enabled' => false]),
            'provider_credential' => fn () => config(['production-membership-billing.secret_key' => 'sk_'.'live_'.'SYNTHETICREHEARSAL']),
            'provider_transport' => fn () => $this->app->detectEnvironment(fn () => 'local'),
        ];
        foreach ($cases as $reason => $change) {
            F::configure(['provider_io_enabled' => true]);
            $this->app->detectEnvironment(fn () => 'testing');
            $change();
            $transport = new BillingHttpFixture($this->routes(F::graph()));
            try {
                (new StripeSdkBillingGateway($transport))->retrieveInvoice(F::INVOICE);
                $this->fail($reason);
            } catch (BillingException $error) {
                $this->assertSame($reason, $error->reason);
            }
            $this->assertSame([], $transport->calls, $reason);
        }
        $this->app->detectEnvironment(fn () => 'testing');
        F::configure(['provider_io_enabled' => true]);
        $transport = new BillingHttpFixture($this->routes(F::graph()));
        DB::beginTransaction();
        try {
            (new StripeSdkBillingGateway($transport))->account();
            $this->fail('Provider I/O inside a held transaction must refuse.');
        } catch (BillingException $error) {
            $this->assertSame('transaction_open', $error->reason);
        } finally {
            DB::rollBack();
        }
        $this->assertSame([], $transport->calls);
    }

    public function test_gateway_has_no_provider_write_method(): void
    {
        $methods = array_map(fn (\ReflectionMethod $m) => $m->getName(), (new \ReflectionClass(StripeSdkBillingGateway::class))->getMethods(\ReflectionMethod::IS_PUBLIC));
        sort($methods);
        $this->assertSame(['__construct', 'account', 'listInvoicePayments', 'provenance', 'retrieveBalanceTransaction', 'retrieveCharge',
            'retrieveInvoice', 'retrievePaymentIntent', 'retrieveSubscription'], $methods);
        $source = file_get_contents((new \ReflectionClass(StripeSdkBillingGateway::class))->getFileName());
        $this->assertDoesNotMatchRegularExpression('/->(create|update|pay|finalizeInvoice|voidInvoice|markUncollectible|sendInvoice|cancel|capture|confirm|attachPayment|del|delete)\(/', $source);
    }

    private function routes(array $graph): array
    {
        return ['/v1/account' => $graph['account'], '/v1/invoices/'.F::INVOICE => $graph['invoice'],
            '/v1/invoice_payments?invoice='.F::INVOICE.'&limit=10' => ['object' => 'list', 'has_more' => false, 'url' => '/v1/invoice_payments', 'data' => $graph['payments']],
            '/v1/payment_intents/'.F::PAYMENT_INTENT => $graph['intents'][F::PAYMENT_INTENT], '/v1/charges/'.F::CHARGE => $graph['charges'][F::CHARGE],
            '/v1/balance_transactions/'.F::BALANCE_TRANSACTION => $graph['transactions'][F::BALANCE_TRANSACTION],
            '/v1/subscriptions/'.F::SUBSCRIPTION => $graph['subscription']];
    }
}
