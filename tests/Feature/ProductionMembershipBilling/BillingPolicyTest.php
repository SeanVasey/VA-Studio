<?php

namespace Tests\Feature\ProductionMembershipBilling;

use App\Domain\Memberships\Billing\BillingException;
use App\Domain\Memberships\Billing\BillingPolicy;
use Tests\Support\BillingStripeFixtures as F;
use Tests\TestCase;

class BillingPolicyTest extends TestCase
{
    public static int $calls = 0;

    public function test_rehearsal_test_mode_is_admitted_in_testing_only_with_complete_facts(): void
    {
        F::configure();
        $current = (new BillingPolicy)->current();
        $this->assertSame(['testing', 'synthetic_rehearsal', 'test'], [$current['environment'], $current['provenance'], $current['mode']]);
        foreach ([['account_ref' => null], ['account_ref' => 'acct_bad-ref'], ['approved_subscription_policy_hash' => null], ['mode' => 'sandbox']] as $change) {
            F::configure($change);
            $this->assertRefused(fn () => (new BillingPolicy)->current());
        }
        F::configure(['provider_io_enabled' => false]);
        $this->assertRefused(fn () => (new BillingPolicy)->providerIo(), 'provider_io_disabled');
        F::configure(['provider_io_enabled' => true, 'secret_key' => 'rk_'.'test_'.'SYNTHETICREHEARSAL']);
        $this->assertRefused(fn () => (new BillingPolicy)->providerIo(), 'provider_credential');
    }

    public function test_replaced_environment_binding_is_refused_without_being_invoked(): void
    {
        F::configure();
        self::$calls = 0;
        $this->app->bind('env', static function (): string {
            self::$calls++;
            config(['production-membership-billing.enabled' => false]);

            return 'testing';
        });
        $this->assertRefused(fn () => (new BillingPolicy)->current(), 'changed_policy');
        $this->assertSame(0, self::$calls);
        $this->assertTrue(config('production-membership-billing.enabled'));
    }

    public function test_object_leaves_and_withdrawn_configuration_refuse(): void
    {
        F::configure();
        $policy = new BillingPolicy;
        $baseline = $policy->current();
        config(['production-membership-billing.mode' => new \stdClass]);
        $this->assertRefused(fn () => $policy->current(), 'changed_policy');
        F::configure(['enabled' => false]);
        $this->assertRefused(fn () => $policy->proveConfiguration($baseline), 'changed_policy');
    }

    private function assertRefused(callable $call, ?string $reason = null): void
    {
        try {
            $call();
            $this->fail('Must refuse.');
        } catch (BillingException $error) {
            if ($reason !== null) {
                $this->assertSame($reason, $error->reason);
            }
        }
    }
}
