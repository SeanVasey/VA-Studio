<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\ExecutionContextV1;
use ArrayObject;
use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionCheckoutProviderFixtures;
use Tests\TestCase;
use Throwable;

/** Actual current/retained execution policy; no product fixture, database initialization or provider call. */
class ProductionCheckoutExecutionMetadataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ProductionCheckoutProviderFixtures::configure();
    }

    private function connectionState(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getRawValue($this->app);
        $manager = $instances['db'] ?? null;

        return [$manager, $manager instanceof DatabaseManager
            ? (new ReflectionProperty(DatabaseManager::class, 'connections'))->getRawValue($manager) : null];
    }

    public function test_canonical_current_context_does_not_initialize_a_database_and_retained_context_stays_original(): void
    {
        $before = $this->connectionState();
        $machine = ProductionCheckoutProviderFixtures::machine();
        $context = ExecutionContextV1::current($machine);
        $binding = $context->binding();
        config(['production_checkout.funds_mode' => 'live', 'production_checkout.account_id' => 'acct_DIFFERENT']);
        $retained = ExecutionContextV1::retained($machine, $binding);
        $this->assertSame('test', $context->fundsMode);
        $this->assertSame('synthetic_rehearsal', $context->provenance);
        $this->assertSame($binding, $retained->binding());
        $this->assertSame($before, $this->connectionState());
    }

    public function test_current_policy_parent_refuses_before_any_arrayaccess_offset(): void
    {
        $machine = ProductionCheckoutProviderFixtures::machine();
        $before = config('production_checkout');
        $parent = new ExecutionMetadataOffsetSentinel($before);
        config(['production_checkout' => $parent]);
        $failure = null;
        try {
            ExecutionContextV1::current($machine);
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            config(['production_checkout' => $before]);
        }
        $this->assertSame(0, $parent->callbacks);
        $this->assertInstanceOf(CheckoutException::class, $failure);
    }

    public function test_retained_context_does_not_read_a_replaced_current_policy_parent(): void
    {
        $machine = ProductionCheckoutProviderFixtures::machine();
        $binding = ExecutionContextV1::current($machine)->binding();
        $before = config('production_checkout');
        $parent = new ExecutionMetadataOffsetSentinel($before);
        config(['production_checkout' => $parent]);
        try {
            $this->assertSame($binding, ExecutionContextV1::retained($machine, $binding)->binding());
            $this->assertSame(0, $parent->callbacks);
        } finally {
            config(['production_checkout' => $before]);
        }
    }

    public function test_current_context_refuses_a_configuration_resolver_without_invoking_it(): void
    {
        $machine = ProductionCheckoutProviderFixtures::machine();
        $property = new ReflectionProperty(Container::class, 'beforeResolvingCallbacks');
        $before = $property->getRawValue($this->app);
        $callbacks = 0;
        $this->app->beforeResolving('config', function () use (&$callbacks): void { $callbacks++; });
        $failure = null;
        try {
            ExecutionContextV1::current($machine);
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $property->setValue($this->app, $before);
        }
        $this->assertSame(0, $callbacks);
        $this->assertInstanceOf(CheckoutException::class, $failure);
    }

    public function test_current_environment_descriptor_refuses_before_offsets_or_resolution(): void
    {
        $machine = ProductionCheckoutProviderFixtures::machine();
        $instances = new ReflectionProperty(Container::class, 'instances');
        $bindings = new ReflectionProperty(Container::class, 'bindings');
        $oldInstances = $instances->getRawValue($this->app);
        $oldBindings = $bindings->getRawValue($this->app);
        $currentInstances = $oldInstances;
        unset($currentInstances['env']);
        $currentBindings = $oldBindings;
        $callbacks = 0;
        $descriptor = new ExecutionMetadataOffsetSentinel(['concrete' => function () use (&$callbacks): string {
            $callbacks++;

            return 'testing';
        }, 'shared' => true]);
        $currentBindings['env'] = $descriptor;
        $instances->setValue($this->app, $currentInstances);
        $bindings->setValue($this->app, $currentBindings);
        $failure = null;
        try {
            ExecutionContextV1::current($machine);
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $instances->setValue($this->app, $oldInstances);
            $bindings->setValue($this->app, $oldBindings);
        }
        $this->assertSame(0, $descriptor->callbacks);
        $this->assertSame(0, $callbacks);
        $this->assertInstanceOf(CheckoutException::class, $failure);
    }

    public static function environments(): array
    {
        return [['test', 'testing', true], ['test', 'local', true], ['test', 'staging', false], ['test', 'production', false],
            ['live', 'testing', true], ['live', 'local', true], ['live', 'staging', false], ['live', 'production', true]];
    }

    #[DataProvider('environments')]
    public function test_existing_funds_environment_policy_is_unchanged(string $mode, string $environment, bool $allowed): void
    {
        $machine = ProductionCheckoutProviderFixtures::machine();
        config(['production_checkout.funds_mode' => $mode]);
        $this->app->instance('env', $environment);
        if (! $allowed) {
            $this->expectException(CheckoutException::class);
        }
        $context = ExecutionContextV1::current($machine);
        $this->assertSame($mode, $context->fundsMode);
    }
}

final class ExecutionMetadataOffsetSentinel extends ArrayObject
{
    public int $callbacks = 0;

    public function offsetExists(mixed $key): bool
    {
        $this->callbacks++;

        return parent::offsetExists($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->callbacks++;

        return parent::offsetGet($key);
    }
}
