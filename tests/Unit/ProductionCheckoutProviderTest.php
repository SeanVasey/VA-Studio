<?php

namespace Tests\Unit;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionCheckout\OwnAccountStripeGateway;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Stripe\Stripe;
use Tests\Support\ProductionCheckoutProviderFixtures as F;
use Tests\Support\ProductionTrackCapabilitiesFixtures;
use Tests\TestCase;

class ProductionCheckoutProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['production_checkout.funds_mode' => 'test', 'production_checkout.account_id' => 'acct_SYNTHETIC',
            'production_checkout.return_origin' => 'https://review.invalid', 'production_checkout.provider_io_enabled' => true,
            'production_checkout.secret_key' => 'sk_'.'test_'.'SYNTHETIC', 'production_checkout.review_lifetime_seconds' => 600]);
    }

    private function machine(): array
    {
        $machine = ProductionTrackCapabilitiesFixtures::machine();
        $machine['choices']['tax_calculation'] = ['strategy' => 'declared_exemption', 'behavior' => 'exclusive', 'maximum_rate_bps' => 0, 'rounding' => 'not_applicable'];
        $machine['choices']['reservation_and_exclusives']['late_time_basis'] = 'application_verified_observation_time';
        $machine['choices']['reservation_and_exclusives']['reservation_seconds'] = 3600;
        $machine['choices']['reservation_and_exclusives']['provider_lifetime_seconds'] = 2400;

        return $machine;
    }

    public function test_context_distinguishes_declared_target_from_rehearsal_and_supports_configured_live_runtime(): void
    {
        $test = ExecutionContextV1::current($this->machine());
        $this->assertSame('live', $test->binding()['commercial_target_mode']);
        $this->assertSame('test', $test->fundsMode);
        $this->assertSame('synthetic_rehearsal', $test->provenance);
        $test->requireBuyer(['provenance' => 'synthetic_rehearsal']);
        config(['production_checkout.funds_mode' => 'live']);
        $live = ExecutionContextV1::current($this->machine());
        $this->assertSame('live', $live->fundsMode);
        $this->assertSame('verified_production', $live->provenance);
        $live->requireBuyer(['provenance' => 'verified_production']);
        $this->expectException(CheckoutException::class);
        $live->requireBuyer(['provenance' => 'synthetic_rehearsal']);
    }

    public static function unsupported(): array
    {
        return [['provider_calculated'], ['guest'], ['currency'], ['capture'], ['short_expiry'], ['late_provider_time'], ['wrong_account'], ['wrong_origin'], ['mode_missing']];
    }

    #[DataProvider('unsupported')]
    public function test_unsupported_choices_are_refused_without_substitution(string $case): void
    {
        $machine = $this->machine();
        match ($case) {
            'provider_calculated' => $machine['choices']['tax_calculation'] = ProductionTrackCapabilitiesFixtures::machine()['choices']['tax_calculation'],
            'guest' => [$machine['choices']['buyer_identity']['mode'], $machine['choices']['recovery']['mode']] = ['verified_guest_claim', 'verified_guest_claim_recovery'],
            'currency' => $machine['choices']['currency']['code'] = 'EUR',
            'capture' => $machine['choices']['provider_account']['capture_method'] = 'manual',
            'short_expiry' => $machine['choices']['reservation_and_exclusives']['provider_lifetime_seconds'] = 600,
            'late_provider_time' => $machine['choices']['reservation_and_exclusives']['late_time_basis'] = 'authoritative_provider_payment_time',
            'wrong_account' => config(['production_checkout.account_id' => 'acct_FOREIGN']),
            'wrong_origin' => config(['production_checkout.return_origin' => 'https://foreign.invalid']),
            'mode_missing' => config(['production_checkout.funds_mode' => null]),
        };
        $this->expectException(CheckoutException::class);
        ExecutionContextV1::current($machine);
    }

    public function test_actual_sdk_own_account_request_is_pinned_and_transport_restored(): void
    {
        $fixture = new ProductionCheckoutHttpFixture([['object' => 'account', 'id' => 'acct_SYNTHETIC']]);
        $previous = ApiRequestor::httpClient();
        $gateway = new OwnAccountStripeGateway($fixture);
        $account = $gateway->account(ExecutionContextV1::current($this->machine()));
        $this->assertSame(['object' => 'account', 'id' => 'acct_SYNTHETIC', 'evidence_origin' => 'synthetic_rehearsal', 'funds_mode' => 'test'], $account);
        $this->assertSame($previous, ApiRequestor::httpClient());
        $call = $fixture->calls[0];
        $this->assertSame('get', $call['method']);
        $this->assertSame('https://api.stripe.com/v1/account', $call['url']);
        $this->assertSame([], $call['params']);
        $headers = implode("\n", $call['headers']);
        $this->assertStringContainsString('Stripe-Version: '.ExecutionContextV1::API_VERSION, $headers);
        $this->assertStringNotContainsString('Stripe-Account:', $headers);
        $this->assertStringNotContainsString('Stripe-Context:', $headers);
        $this->assertSame(0, $call['maxNetworkRetries']);
    }

    public function test_actual_sdk_create_then_retrieve_and_payment_get_use_one_frozen_request_and_own_account(): void
    {
        F::configure();
        CarbonImmutable::setTestNow('2026-10-07T12:00:00Z');
        try {
            $request = F::request();
            $session = F::session($request['params'], 'complete', true);
            $payment = [...F::payment($request['params']), 'client_secret' => 'PRIVATE_SDK_SECRET'];
            $account = ['object' => 'account', 'id' => 'acct_SYNTHETIC'];
            $fixture = new ProductionCheckoutHttpFixture([$account, $session, $account, $session, $account, $payment]);
            $previous = ApiRequestor::httpClient();
            $gateway = new OwnAccountStripeGateway($fixture);
            $context = ExecutionContextV1::current(F::machine());
            $this->assertSame($session, $gateway->create($context, $request['params'], $request['idempotency_key']));
            $this->assertSame($session, $gateway->retrieve($context, $session['id']));
            unset($payment['client_secret']);
            $this->assertSame($payment, $gateway->paymentIntent($context, $payment['id']));
            $this->assertSame($previous, ApiRequestor::httpClient());
            $this->assertCount(6, $fixture->calls);
            $wireParams = $request['params'];
            $wireParams['automatic_tax']['enabled'] = 'false';
            $wireParams['adaptive_pricing']['enabled'] = 'false';
            $wireParams['allow_promotion_codes'] = 'false';
            $this->assertSame($wireParams, $fixture->calls[1]['params']);
            $this->assertStringContainsString('Idempotency-Key: '.$request['idempotency_key'], implode("\n", $fixture->calls[1]['headers']));
            $this->assertSame('post', $fixture->calls[1]['method']);
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $fixture->calls[1]['url']);
            $this->assertSame('get', $fixture->calls[3]['method']);
            $this->assertSame(['expand' => ['line_items.data.price.product']], $fixture->calls[3]['params']);
            $this->assertSame('get', $fixture->calls[5]['method']);
            foreach ($fixture->calls as $call) {
                $this->assertStringNotContainsString('Stripe-Account:', implode("\n", $call['headers']));
                $this->assertSame(0, $call['maxNetworkRetries']);
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_expiry_that_elapsed_during_own_account_read_is_refused_before_checkout_post(): void
    {
        F::configure();
        $request = F::request();
        CarbonImmutable::setTestNow('2026-10-07T12:31:00Z');
        $fixture = new ProductionCheckoutHttpFixture([['object' => 'account', 'id' => 'acct_SYNTHETIC']]);
        try {
            (new OwnAccountStripeGateway($fixture))->create(ExecutionContextV1::current(F::machine()), $request['params'], $request['idempotency_key']);
            $this->fail('Expired first request posted to provider.');
        } catch (CheckoutException) {
            $this->assertCount(1, $fixture->calls);
            $this->assertSame('https://api.stripe.com/v1/account', $fixture->calls[0]['url']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public static function providerRefusals(): array
    {
        return [['disabled'], ['wrong_key_mode'], ['connect'], ['ssl'], ['logger'], ['transaction'], ['raw_transaction'], ['fixture_live'], ['foreign_account'], ['throw_private']];
    }

    #[DataProvider('providerRefusals')]
    public function test_provider_failure_is_sanitized_and_never_escapes_an_injected_live_transport(string $case): void
    {
        $fixture = new ProductionCheckoutHttpFixture([['object' => 'account', 'id' => $case === 'foreign_account' ? 'acct_FOREIGN' : 'acct_SYNTHETIC']], $case === 'throw_private');
        $previous = ApiRequestor::httpClient();
        $account = Stripe::$accountId;
        $ssl = Stripe::$verifySslCerts;
        $logger = Stripe::$logger;
        match ($case) {
            'disabled' => config(['production_checkout.provider_io_enabled' => false]),
            'wrong_key_mode' => config(['production_checkout.secret_key' => 'sk_'.'live_'.'SYNTHETIC']),
            'connect' => Stripe::$accountId = 'acct_FOREIGN',
            'ssl' => Stripe::$verifySslCerts = false,
            'logger' => Stripe::$logger = new \stdClass,
            'transaction' => DB::beginTransaction(),
            'raw_transaction' => DB::connection()->getPdo()->beginTransaction(),
            'fixture_live' => config(['production_checkout.funds_mode' => 'live']),
            default => null,
        };
        try {
            (new OwnAccountStripeGateway($fixture))->account(ExecutionContextV1::current($this->machine()));
            $this->fail('Unsafe provider operation succeeded.');
        } catch (CheckoutException $error) {
            $this->assertSame('PRODUCTION_CHECKOUT_UNAVAILABLE', $error->getMessage());
            $this->assertNull($error->getPrevious());
            $this->assertSame($previous, ApiRequestor::httpClient());
            $this->assertCount(in_array($case, ['foreign_account', 'throw_private'], true) ? 1 : 0, $fixture->calls);
        } finally {
            Stripe::$accountId = $account;
            Stripe::$verifySslCerts = $ssl;
            Stripe::$logger = $logger;
            if ($case === 'transaction') {
                DB::rollBack();
            } elseif ($case === 'raw_transaction') {
                DB::connection()->getPdo()->rollBack();
            }
        }
    }
}

final class ProductionCheckoutHttpFixture implements ClientInterface
{
    public array $calls = [];

    public function __construct(private array $responses, private bool $throws = false) {}

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $this->calls[] = ['method' => $method, 'url' => $absUrl, 'headers' => $headers, 'params' => $params, 'maxNetworkRetries' => $maxNetworkRetries];
        if ($this->throws) {
            throw new \RuntimeException('PRIVATE REQUEST BUYER SECRET URL');
        }

        return [json_encode(array_shift($this->responses), JSON_THROW_ON_ERROR), 200, []];
    }
}
