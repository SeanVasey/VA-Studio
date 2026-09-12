<?php

namespace Tests\Unit;

use App\Domain\Commerce\PricingPolicy;
use App\Domain\Commerce\QuoteException;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\PricingFixtures;
use Tests\TestCase;

class PricingPolicyTest extends TestCase
{
    public static function invalidPolicies(): array
    {
        return [
            ['schema_version', 2], ['scope', 'live'], ['key', ''], ['version', '1'], ['version', 0], ['version', 1.0],
            ['currency', 'EUR'], ['provider', 'unknown'], ['account', ''], ['account', 'acct_../secret'],
            ['effective_from', '2026-02-30T00:00:00Z'], ['effective_until', '2019-01-01T00:00:00Z'],
            ['effective_until', '2020-01-01T00:00:00Z'], ['effective_from', '2020-01-01T00:00:00+00:00'],
            ['effective_from', '2020-01-01T00:00:00.000Z'], ['extra', 'ignored?'], ['tax.extra', true],
            ['tax.rate_bps', '750'], ['tax.rate_bps', 10001], ['tax.rate_bps', -1],
            ['tax.behavior', 'inclusive'], ['tax.rounding', 'basket_half_up'], ['tax.mode', 'unresolved'],
        ];
    }

    #[DataProvider('invalidPolicies')]
    public function test_incomplete_ambiguous_and_unsupported_policies_fail(string $field, mixed $value): void
    {
        $policy = PricingFixtures::policy();
        Arr::set($policy, $field, $value);
        $this->expectException(InvalidArgumentException::class);
        app(PricingPolicy::class)->validate($policy);
    }

    public function test_missing_policy_stays_unknown_and_configured_zero_is_explicit(): void
    {
        PricingFixtures::configure(null);
        $this->assertNull(app(PricingPolicy::class)->current());
        $policy = PricingFixtures::policy();
        $policy['tax']['rate_bps'] = 0;
        PricingFixtures::configure($policy);
        $this->assertSame(0, app(PricingPolicy::class)->current()['tax']['rate_bps']);
        PricingFixtures::configure(PricingFixtures::policy('provider_calculated'));
        $this->assertSame('provider_calculated', app(PricingPolicy::class)->current()['tax']['mode']);
    }

    public function test_effective_period_is_half_open_at_exact_utc_seconds(): void
    {
        $policy = PricingFixtures::policy();
        $policy['effective_from'] = '2026-09-12T00:00:00Z';
        $policy['effective_until'] = '2026-09-12T00:01:00Z';
        PricingFixtures::configure($policy);
        $this->travelTo(app(PricingPolicy::class)->timestamp($policy['effective_from']));
        $this->assertSame($policy, app(PricingPolicy::class)->current());
        $this->travelTo(app(PricingPolicy::class)->timestamp($policy['effective_until']));
        $this->expectException(QuoteException::class);
        app(PricingPolicy::class)->current();
    }

    public static function unavailableConfiguration(): array
    {
        return [['{'], ['null'], ['[]'], ['false'], ['""'], [false], [str_repeat(' ', 8193)]];
    }

    #[DataProvider('unavailableConfiguration')]
    public function test_bad_configuration_has_a_generic_failure(mixed $value): void
    {
        config(['commerce.test_pricing_policy' => $value]);
        try {
            app(PricingPolicy::class)->current();
            $this->fail('Invalid configuration was used.');
        } catch (QuoteException $exception) {
            $this->assertSame('PRICING_UNAVAILABLE', $exception->errorCode);
            $this->assertSame(503, $exception->status);
        }
    }

    public function test_test_policy_cannot_be_enabled_in_production(): void
    {
        PricingFixtures::configure(PricingFixtures::policy());
        $this->app->instance('env', 'production');
        $this->expectException(QuoteException::class);
        app(PricingPolicy::class)->current();
    }
}
