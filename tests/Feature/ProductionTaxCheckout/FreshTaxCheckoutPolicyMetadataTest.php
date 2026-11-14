<?php

namespace Tests\Feature\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionTaxCheckout\FreshTaxCheckoutPolicy;
use Illuminate\Container\Container;
use ReflectionProperty;
use Tests\TestCase;

class FreshTaxCheckoutPolicyMetadataTest extends TestCase
{
    public function test_nonarray_environment_descriptor_is_refused_before_pure_offset_code(): void
    {
        config(['production-tax-checkout.enabled' => true]);
        $bindings = new ReflectionProperty(Container::class, 'bindings');
        $instances = new ReflectionProperty(Container::class, 'instances');
        $originalBindings = $bindings->getValue($this->app);
        $originalInstances = $instances->getValue($this->app);
        $this->app['env'] = 'testing';
        $changed = $bindings->getValue($this->app);
        $descriptor = new FreshTaxEnvironmentDescriptorFixture($changed['env']);
        $changed['env'] = $descriptor;
        $bindings->setValue($this->app, $changed);
        $failure = null;
        try {
            try {
                FreshTaxCheckoutPolicy::capture();
            } catch (CheckoutException $error) {
                $failure = $error;
            }
        } finally {
            $bindings->setValue($this->app, $originalBindings);
            $instances->setValue($this->app, $originalInstances);
        }
        $this->assertSame(0, $descriptor->pureCalls, 'Pure original-policy admission executed environment offset code.');
        $this->assertInstanceOf(CheckoutException::class, $failure);
        $this->assertSame(['disabled', 503], [$failure->reason, $failure->status]);
    }

    public function test_genuine_laravel_value_closure_remains_admitted(): void
    {
        config(['production-tax-checkout.enabled' => true]);
        $this->app['env'] = 'testing';
        $policy = FreshTaxCheckoutPolicy::capture();
        $policy->prove();
        $this->assertSame('testing', $this->app->environment());
    }

    public function test_literal_environment_instance_remains_admitted(): void
    {
        config(['production-tax-checkout.enabled' => true]);
        $instances = new ReflectionProperty(Container::class, 'instances');
        $original = $instances->getValue($this->app);
        $instances->setValue($this->app, [...$original, 'env' => 'testing']);
        try {
            $policy = FreshTaxCheckoutPolicy::capture();
            $policy->prove();
            $this->assertSame('testing', $this->app->environment());
        } finally {
            $instances->setValue($this->app, $original);
        }
    }
}

final class FreshTaxEnvironmentDescriptorFixture extends \ArrayObject
{
    public int $pureCalls = 0;

    public function offsetGet(mixed $key): mixed
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (($frame['class'] ?? null) === FreshTaxCheckoutPolicy::class && ($frame['function'] ?? null) === 'environment') {
                $this->pureCalls++;
                break;
            }
        }

        return parent::offsetGet($key);
    }
}
