<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutPolicy;
use App\Domain\Commerce\ProductionTaxCheckout\TaxCheckoutTransport;
use App\Domain\Commerce\ProductionTaxCheckout\TaxExecutionContext;
use App\Domain\Commerce\ProductionTaxCheckout\UnboundTaxCheckoutTransport;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\Stripe;
use Stripe\Util\ApiVersion;
use Tests\Support\ProductionTaxCheckoutFixtures;
use Tests\Support\RecordingTaxCheckoutTransport;
use Tests\TestCase;

/** Default-off refusal matrix: only literal `true` in local/testing enables; only an admitted transport may be used. */
class ProductionTaxCheckoutPolicyTest extends TestCase
{
    use ProductionTaxCheckoutFixtures;

    public static function environments(): array
    {
        $cases = [];
        foreach (['production', 'staging', 'local', 'testing'] as $environment) {
            foreach (['true' => true, 'false' => false, 'null' => null, 'string' => 'true', 'one' => 1] as $label => $value) {
                $cases[$environment.' / '.$label] = [$environment, $value, in_array($environment, ['local', 'testing'], true) && $value === true];
            }
        }

        return $cases;
    }

    #[DataProvider('environments')]
    public function test_policy_is_enabled_only_by_literal_true_in_local_or_testing(string $environment, mixed $value, bool $enabled): void
    {
        $this->app['env'] = $environment;
        config(['production-tax-checkout.enabled' => $value]);
        $this->assertSame($enabled, TaxCheckoutPolicy::enabled());
        try {
            TaxCheckoutPolicy::requireEnabled();
            $this->assertTrue($enabled);
        } catch (CheckoutException $error) {
            $this->assertFalse($enabled);
            $this->assertSame(['disabled', 503], [$error->reason, $error->status]);
        }
    }

    public function test_shipped_configuration_is_off_unbound_literal_and_pinned(): void
    {
        $shipped = require config_path('production-tax-checkout.php');
        $this->assertSame(['enabled' => false, 'provider' => null, 'funds_mode' => null, 'account_id' => null, 'return_origin' => null,
            'stripe_sdk_version' => '21.3.2', 'stripe_api_version' => '2026-08-26.dahlia'], $shipped);
        $this->assertFalse(TaxCheckoutPolicy::enabled());
        $source = (string) file_get_contents(config_path('production-tax-checkout.php'));
        $this->assertStringNotContainsString('env(', $source);
        $this->assertSame(ExecutionContextV1::API_VERSION, TaxCheckoutPolicy::STRIPE_API_VERSION);
        $lock = json_decode((string) file_get_contents(base_path('composer.lock')), true, 512, JSON_THROW_ON_ERROR);
        $stripe = array_values(array_filter($lock['packages'], fn (array $package): bool => $package['name'] === 'stripe/stripe-php'));
        $this->assertSame('v'.TaxCheckoutPolicy::STRIPE_SDK_VERSION, $stripe[0]['version']);
        $this->assertSame([TaxCheckoutPolicy::STRIPE_SDK_VERSION, TaxCheckoutPolicy::STRIPE_API_VERSION], [Stripe::VERSION, ApiVersion::CURRENT]);
    }

    public function test_pin_drift_refuses_even_when_enabled(): void
    {
        self::configureTax();
        TaxCheckoutPolicy::requireEnabled();
        foreach (['stripe_sdk_version' => '21.4.0', 'stripe_api_version' => '2026-09-30.endive'] as $key => $value) {
            config(['production-tax-checkout.'.$key => $value]);
            try {
                TaxCheckoutPolicy::requireEnabled();
                $this->fail($key.' drift was accepted.');
            } catch (CheckoutException $error) {
                $this->assertSame('unsupported', $error->reason);
            }
            config(['production-tax-checkout.stripe_sdk_version' => '21.3.2', 'production-tax-checkout.stripe_api_version' => '2026-08-26.dahlia']);
        }
    }

    public static function transports(): array
    {
        return [
            'no provider configured' => [null, fn () => new RecordingTaxCheckoutTransport, false],
            'empty provider' => ['', fn () => new RecordingTaxCheckoutTransport, false],
            'no transport bound' => ['synthetic-tax-transport-v1', fn () => null, false],
            'refusing default named' => ['unbound', fn () => new UnboundTaxCheckoutTransport, false],
            'identity mismatch' => ['another-transport', fn () => new RecordingTaxCheckoutTransport, false],
            'non-string provider' => [true, fn () => new RecordingTaxCheckoutTransport, false],
            'matching identity' => ['synthetic-tax-transport-v1', fn () => new RecordingTaxCheckoutTransport, true],
        ];
    }

    #[DataProvider('transports')]
    public function test_transport_admission_fails_closed_before_any_call(mixed $provider, Closure $transport, bool $admitted): void
    {
        self::configureTax();
        config(['production-tax-checkout.provider' => $provider]);
        $bound = $transport();
        try {
            $this->assertSame($bound, TaxCheckoutPolicy::transport($bound));
            $this->assertTrue($admitted);
        } catch (CheckoutException $error) {
            $this->assertFalse($admitted);
            $this->assertSame(['provider_unbound', 503], [$error->reason, $error->status]);
        }
        if ($bound instanceof RecordingTaxCheckoutTransport) {
            $this->assertSame([], $bound->calls);
        }
        // Even admitted, a disabled policy refuses the transport.
        config(['production-tax-checkout.enabled' => false]);
        $this->expectException(CheckoutException::class);
        TaxCheckoutPolicy::transport($bound);
    }

    public function test_unbound_transport_refuses_every_operation(): void
    {
        self::configureTax();
        $context = TaxExecutionContext::current(self::taxMachine());
        $unbound = new UnboundTaxCheckoutTransport;
        foreach ([fn () => $unbound->create($context, [], 'key'), fn () => $unbound->retrieve($context, 'cs_test_X'), fn () => $unbound->paymentIntent($context, 'pi_X')] as $call) {
            try {
                $call();
                $this->fail('The unbound transport performed an operation.');
            } catch (CheckoutException $error) {
                $this->assertSame('provider_unbound', $error->reason);
            }
        }
        $this->assertInstanceOf(TaxCheckoutTransport::class, $unbound);
    }

    public static function contexts(): array
    {
        return [
            'declared exemption strategy' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['tax_calculation' => ['strategy' => 'declared_exemption', 'rounding' => 'not_applicable', 'maximum_rate_bps' => 0]]]), []],
            'manual capture' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['provider_account' => ['capture_method' => 'manual']]]), []],
            'guest buyers' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['buyer_identity' => ['mode' => 'verified_guest_claim'], 'recovery' => ['mode' => 'verified_guest_claim_recovery']]]), []],
            'other currency' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['currency' => ['code' => 'EUR']]]), []],
            'other api version' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['provider_account' => ['api_version' => '2026-09-30.endive']]]), []],
            'provider lifetime below Stripe minimum plus retry' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['reservation_and_exclusives' => ['provider_lifetime_seconds' => 1900]]]), []],
            'provider payment time basis' => [fn (array $m): array => array_replace_recursive($m, ['choices' => ['reservation_and_exclusives' => ['late_time_basis' => 'authoritative_provider_payment_time']]]), []],
            'live funds' => [fn (array $m): array => $m, ['production-tax-checkout.funds_mode' => 'live']],
            'missing funds mode' => [fn (array $m): array => $m, ['production-tax-checkout.funds_mode' => null]],
            'configured account differs' => [fn (array $m): array => $m, ['production-tax-checkout.account_id' => 'acct_OTHER']],
            'missing account' => [fn (array $m): array => $m, ['production-tax-checkout.account_id' => null]],
            'configured return origin differs' => [fn (array $m): array => $m, ['production-tax-checkout.return_origin' => 'https://other.invalid']],
        ];
    }

    #[DataProvider('contexts')]
    public function test_execution_context_refuses_every_unsupported_choice(Closure $machine, array $config): void
    {
        self::configureTax();
        $this->assertSame('provider_calculated', TaxExecutionContext::current(self::taxMachine())->binding()['tax']['strategy']);
        config($config);
        $this->expectExceptionObject(new CheckoutException('unsupported'));
        TaxExecutionContext::current($machine(self::taxMachine()));
    }

    public function test_execution_context_refuses_test_funds_outside_local_or_testing(): void
    {
        self::configureTax();
        $this->app['env'] = 'production';
        $this->expectExceptionObject(new CheckoutException('unsupported'));
        TaxExecutionContext::current(self::taxMachine());
    }
}
