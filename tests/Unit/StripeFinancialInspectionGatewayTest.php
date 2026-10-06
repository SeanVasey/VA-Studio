<?php

namespace Tests\Unit;

use App\Domain\Commerce\Payments\PaymentFinancialEvidence;
use App\Domain\Commerce\Payments\PaymentVerificationException;
use App\Domain\Commerce\Payments\StripeFinancialInspectionGateway;
use App\Domain\Commerce\Payments\StripeSdkCheckoutGateway;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Tests\Support\CheckoutFixtures;
use Tests\Support\PaymentFinancialFixtures as F;
use Tests\Support\PaymentFixtures;
use Tests\TestCase;
use Throwable;

class StripeFinancialInspectionGatewayTest extends TestCase
{
    private array $globals;

    private ClientInterface $transport;

    protected function setUp(): void
    {
        parent::setUp();
        config(['payments.stripe.mode' => 'test', 'payments.stripe.account_id' => CheckoutFixtures::ACCOUNT,
            'payments.stripe.secret_key' => 'sk_test_syntheticFinancialCredential']);
        $this->globals = [Stripe::$accountId, Stripe::$verifySslCerts];
        $this->transport = ApiRequestor::httpClient();
        Stripe::$accountId = null;
        Stripe::$verifySslCerts = true;
    }

    protected function tearDown(): void
    {
        [Stripe::$accountId, Stripe::$verifySslCerts] = $this->globals;
        ApiRequestor::setHttpClient($this->transport);
        $this->app->instance('env', 'testing');
        parent::tearDown();
    }

    private function source(): array
    {
        return F::source(PaymentFixtures::payment(['amount_total' => 1200, 'metadata' => ['fixture' => 'synthetic']]));
    }

    private function expected(array $source): array
    {
        $payment = $source['payment'];
        unset($payment['latest_charge'], $payment['confirmation_method']);

        return ['account_id' => CheckoutFixtures::ACCOUNT, 'amount_minor' => 1200, 'payment' => $payment];
    }

    public function test_get_only_adapter_binds_account_and_payment_and_requests_complete_bounded_lists(): void
    {
        $source = $this->source();
        $source['payment']['client_secret'] = 'do_not_retain';
        $http = new FinancialHttpFixture([['id' => CheckoutFixtures::ACCOUNT, 'object' => 'account'],
            $source['payment'], $source['charge_before'], $source['refunds'], $source['disputes'], $source['charge_after']]);
        $result = (new StripeSdkCheckoutGateway($http))->financialState(PaymentFixtures::PAYMENT);
        $this->assertInstanceOf(StripeSdkCheckoutGateway::class, app(StripeFinancialInspectionGateway::class));
        $this->assertArrayNotHasKey('client_secret', $result['payment']);
        $paths = ['account', 'payment_intents/pi_SYNTHETICPAYMENT', 'charges/ch_SYNTHETICCHARGE', 'refunds', 'disputes', 'charges/ch_SYNTHETICCHARGE'];
        $this->assertCount(6, $http->requests);
        foreach ($http->requests as $index => $request) {
            $this->assertSame('get', $request['method']);
            $this->assertSame('https://api.stripe.com/v1/'.$paths[$index], $request['url']);
            $this->assertSame(in_array($index, [3, 4], true) ? ['payment_intent' => PaymentFixtures::PAYMENT, 'limit' => 100] : [], $request['params']);
            $this->assertSame(0, $request['retries']);
            $this->assertSame('v1', $request['apiMode']);
            $this->assertFalse($request['hasFile']);
            $this->assertContains('Stripe-Version: 2026-08-26.dahlia', $request['headers']);
            $this->assertSame([], array_values(array_filter($request['headers'], fn ($value) => preg_match('/^(Stripe-Account|Stripe-Context|Idempotency-Key):/', $value))));
        }
        $this->assertSame($this->transport, ApiRequestor::httpClient());
    }

    public static function guards(): array
    {
        return [['locator'], ['account'], ['live'], ['environment'], ['tls'], ['connect'], ['secondary_transaction'], ['transport'], ['charge_locator']];
    }

    #[DataProvider('guards')]
    public function test_adapter_guards_and_errors_are_closed_and_redacted(string $case): void
    {
        $source = $this->source();
        $responses = [['id' => CheckoutFixtures::ACCOUNT, 'object' => 'account'], $source['payment']];
        $locator = PaymentFixtures::PAYMENT;
        if ($case === 'locator') {
            $locator = 'pi_other/confirm';
        }
        if ($case === 'account') {
            $responses[0]['id'] = 'acct_foreign';
        }
        if ($case === 'live') {
            config(['payments.stripe.mode' => 'live']);
        }
        if ($case === 'environment') {
            $this->app->instance('env', 'production');
        }
        if ($case === 'tls') {
            Stripe::$verifySslCerts = false;
        }
        if ($case === 'connect') {
            Stripe::$accountId = 'acct_foreign';
        }
        if ($case === 'transport') {
            $responses = [new RuntimeException('private-financial@example.test sk_test_secret')];
        }
        if ($case === 'charge_locator') {
            $responses[1]['latest_charge'] = 'ch_other/refund';
        }
        if ($case === 'secondary_transaction') {
            $primary = Mockery::mock(Connection::class);
            $primary->shouldReceive('transactionLevel')->andReturn(0);
            $secondary = Mockery::mock(Connection::class);
            $secondary->shouldReceive('transactionLevel')->andReturn(1);
            DB::shouldReceive('getConnections')->andReturn([$primary, $secondary]);
        }
        $http = new FinancialHttpFixture($responses);
        try {
            (new StripeSdkCheckoutGateway($http))->financialState($locator);
            $this->fail('Unsafe provider request accepted.');
        } catch (RuntimeException $error) {
            $this->assertSame('STRIPE_CHECKOUT_UNAVAILABLE', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString('private-financial', (string) $error);
        }
        $this->assertCount(match ($case) {
            'account', 'transport' => 1, 'charge_locator' => 2, default => 0
        }, $http->requests);
        $this->assertSame($this->transport, ApiRequestor::httpClient());
    }

    public static function malformed(): array
    {
        return array_map(fn ($case) => [$case], ['account', 'payment_amount', 'payment_currency', 'payment_live', 'payment_connect', 'metadata',
            'charge_payment', 'charge_live', 'charge_amount', 'charge_currency', 'charge_changed', 'charge_connect', 'refunds_partial', 'disputes_partial',
            'list_missing', 'list_associative', 'list_overflow', 'refund_duplicate', 'refund_charge', 'refund_payment', 'refund_status', 'refund_amount',
            'refund_currency', 'refund_live', 'refund_connect', 'dispute_payment', 'dispute_live', 'dispute_status', 'dispute_amount', 'dispute_missing', 'refund_missing']);
    }

    #[DataProvider('malformed')]
    public function test_foreign_partial_inconsistent_or_unsupported_evidence_never_becomes_observed(string $case): void
    {
        $source = $this->source();
        $expected = $this->expected($source);
        $source['refunds']['data'] = [F::item('refund', 'pending', 100)];
        $source['disputes']['data'] = [F::item('dispute', 'warning_needs_response', 1200)];
        match ($case) {
            'account' => $source['account_id'] = 'acct_other',
            'payment_amount' => $source['payment']['amount'] = 1199,
            'payment_currency' => $source['payment']['currency'] = 'gbp',
            'payment_live' => $source['payment']['livemode'] = true,
            'payment_connect' => $source['payment']['on_behalf_of'] = 'acct_other',
            'metadata' => $source['payment']['metadata'] = [],
            'charge_payment' => $source['charge_before']['payment_intent'] = 'pi_other',
            'charge_live' => $source['charge_before']['livemode'] = true,
            'charge_amount' => $source['charge_before']['amount'] = 1199,
            'charge_currency' => $source['charge_before']['currency'] = 'gbp',
            'charge_changed' => $source['charge_after']['amount_refunded'] = 100,
            'charge_connect' => $source['charge_before']['transfer_data'] = ['destination' => 'acct_other'],
            'refunds_partial' => $source['refunds']['has_more'] = true,
            'disputes_partial' => $source['disputes']['has_more'] = true,
            'list_missing' => $source['refunds'] = [],
            'list_associative' => $source['refunds']['data'] = ['wrong' => F::item('refund', 'pending', 100)],
            'list_overflow' => $source['refunds']['data'] = array_fill(0, 101, F::item('refund', 'pending', 100)),
            'refund_duplicate' => $source['refunds']['data'][] = $source['refunds']['data'][0],
            'refund_charge' => $source['refunds']['data'][0]['charge'] = 'ch_other',
            'refund_payment' => $source['refunds']['data'][0]['payment_intent'] = 'pi_other',
            'refund_status' => $source['refunds']['data'][0]['status'] = null,
            'refund_amount' => $source['refunds']['data'][0]['amount'] = 1201,
            'refund_currency' => $source['refunds']['data'][0]['currency'] = 'gbp',
            'refund_live' => $source['refunds']['data'][0]['livemode'] = true,
            'refund_connect' => $source['refunds']['data'][0]['transfer_reversal'] = 'trr_other',
            'dispute_payment' => $source['disputes']['data'][0]['payment_intent'] = 'pi_other',
            'dispute_live' => $source['disputes']['data'][0]['livemode'] = true,
            'dispute_status' => $source['disputes']['data'][0]['status'] = 'new_unknown_status',
            'dispute_amount' => $source['disputes']['data'][0]['amount'] = 0,
            'dispute_missing' => $source['disputes']['data'] = [],
            'refund_missing' => $source['refunds']['data'] = [],
        };
        if ($case === 'dispute_missing') {
            $source['charge_before']['disputed'] = $source['charge_after']['disputed'] = true;
        }
        if ($case === 'refund_missing') {
            $source['charge_before']['amount_refunded'] = $source['charge_after']['amount_refunded'] = 100;
        }
        try {
            app(PaymentFinancialEvidence::class)->capture($source, $expected);
            $this->fail('Unsafe financial evidence accepted.');
        } catch (PaymentVerificationException $error) {
            $this->assertSame(str_ends_with($case, '_partial') ? 'financial_incomplete' : 'financial_attention', $error->reason);
        }
    }

    public static function observedStatuses(): array
    {
        return [['refund', 'pending'], ['refund', 'requires_action'], ['refund', 'succeeded'], ['refund', 'failed'], ['refund', 'canceled'],
            ['dispute', 'warning_needs_response'], ['dispute', 'warning_under_review'], ['dispute', 'warning_closed'], ['dispute', 'needs_response'],
            ['dispute', 'under_review'], ['dispute', 'won'], ['dispute', 'lost'], ['dispute', 'prevented']];
    }

    #[DataProvider('observedStatuses')]
    public function test_statuses_are_retained_as_facts_without_invented_financial_effects(string $kind, string $status): void
    {
        $source = $this->source();
        $expected = $this->expected($source);
        $source[$kind.'s']['data'] = [F::item($kind, $status, 100)];
        $result = app(PaymentFinancialEvidence::class)->capture($source, $expected);
        $this->assertSame('observed', $result['state']);
        $this->assertSame($status, $result['source'][$kind.'s']['data'][0]['status']);
        $this->assertStringNotContainsString('private-financial@example.test', json_encode($result, JSON_THROW_ON_ERROR));
    }
}

final class FinancialHttpFixture implements ClientInterface
{
    public array $requests = [];

    public function __construct(private array $responses) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params,
            'hasFile' => $hasFile, 'apiMode' => $apiMode, 'retries' => $maxNetworkRetries];
        $response = array_shift($this->responses) ?? throw new RuntimeException('Unexpected financial request.');
        if ($response instanceof Throwable) {
            throw $response;
        }

        return [json_encode($response, JSON_THROW_ON_ERROR), 200, ['request-id' => 'req_synthetic']];
    }
}
