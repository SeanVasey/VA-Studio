<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRequestIdentity;
use ArrayObject;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Stringable;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Restored from Codex's ff0 capsule (`tests/Feature/ProductionFreeIdentity/ProductionFreeHttpConfigurationAdmissionTest.php`
 * at ff0f7a85), recomposed on the canonical family 256 configuration parents (`app`, `auth`,
 * `production-customer-identity`, `production-free-grants`). A callback-capable parent or typed leaf planted after the
 * capture is refused before any offset or string conversion, and a cached `null` environment stays legitimate.
 */
final class ProductionFreeGrantHttpConfigurationAdmissionTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public static function extensions(): array
    {
        return array_map(fn (string $parent): array => [$parent], ['items', 'app', 'auth',
            'production-customer-identity', 'production-free-grants', 'key', 'environment']);
    }

    public function test_unconfigured_default_off_request_keeps_its_policy_refusal(): void
    {
        $this->identitySetup();
        try {
            ProductionFreeGrantRequestIdentity::from(Request::create('/production-free-grants/library'));
            $this->fail('Unconfigured family 256 must remain unavailable.');
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame('disabled', $error->reason);
        }
    }

    public function test_a_cached_null_environment_is_a_legitimate_original_state(): void
    {
        $this->freeSetup();
        $owner = $this->customer();
        $instances = new ReflectionProperty(Container::class, 'instances');
        // ff0f7a85: the container normally holds the environment as a literal binding, so the cached instance is null.
        $this->assertNull($instances->getValue($this->app)['env'] ?? null);
        $identity = ProductionFreeGrantRequestIdentity::from($this->request($owner));
        $identity->proveCurrent();
        // A cached non-empty string is equally admissible as the original state.
        $this->app->instance('env', 'testing');
        $cached = ProductionFreeGrantRequestIdentity::from($this->request($owner));
        $cached->proveCurrent();
    }

    #[DataProvider('extensions')]
    public function test_retained_request_refuses_callback_parents_and_leaves_before_any_offset_or_string_conversion(string $parent): void
    {
        $this->freeSetup();
        $owner = $this->customer();
        $request = $this->request($owner);
        $marker = $request->session()->get('_production_customer_identity');
        $identity = ProductionFreeGrantRequestIdentity::from($request);
        $repository = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $before = $property->getValue($repository);
        $instances = new ReflectionProperty(Container::class, 'instances');
        $beforeInstances = $instances->getValue($this->app);
        $value = match ($parent) {
            'items' => $before,
            'key', 'environment' => [],
            default => $before[$parent],
        };
        $callback = new FreeHttpConfigurationExtension($value, $request);
        $items = $before;
        $currentInstances = $beforeInstances;
        if ($parent === 'items') {
            $items = $callback;
        } elseif ($parent === 'key') {
            $items['app']['key'] = $callback;
        } elseif ($parent === 'environment') {
            $currentInstances['env'] = $callback;
        } else {
            $items[$parent] = $callback;
        }
        $refused = false;
        try {
            $property->setValue($repository, $items);
            $instances->setValue($this->app, $currentInstances);
            $identity->proveCurrent();
        } catch (ProductionFreeGrantException $error) {
            $refused = $error->reason === 'identity_refused';
        } finally {
            $property->setValue($repository, $before);
            $instances->setValue($this->app, $beforeInstances);
        }
        $this->assertTrue($refused);
        $this->assertSame(0, $callback->calls);
        $this->assertSame($marker, $request->session()->get('_production_customer_identity'));
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    private function request(array $owner): Request
    {
        Auth::guard('customer')->setUser($owner['user']);
        $request = Request::create('/production-free-grants/library');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('_production_customer_identity', ['binding_digest' => $owner['principal']->sessionBindingDigest()]);
        $request->setUserResolver(fn () => $owner['user']);

        return $request;
    }
}

final class FreeHttpConfigurationExtension extends ArrayObject implements Stringable
{
    public int $calls = 0;

    public function __construct(array $values, private readonly Request $request)
    {
        parent::__construct($values);
    }

    public function offsetGet(mixed $key): mixed
    {
        $this->withdraw();

        return parent::offsetGet($key);
    }

    public function __toString(): string
    {
        $this->withdraw();

        return 'callback-config';
    }

    private function withdraw(): void
    {
        $this->calls++;
        $this->request->session()->forget('_production_customer_identity');
    }
}
