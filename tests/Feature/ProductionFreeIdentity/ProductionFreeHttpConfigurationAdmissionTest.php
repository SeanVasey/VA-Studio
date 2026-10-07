<?php

namespace Tests\Feature\ProductionFreeIdentity;

use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\Production\ProductionFreeGrantHttpIdentity;
use App\Domain\Grants\Free\Production\ProductionFreeGrantIdentityPolicy;
use ArrayObject;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Stringable;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionFreeHttpConfigurationAdmissionTest extends TestCase
{
    use ProductionIdentityFixture;

    public static function extensions(): array
    {
        return array_map(fn (string $parent): array => [$parent], ['items', 'app', 'auth',
            'production-customer-identity', 'production-free-grant-identity', 'free-grants', 'key', 'environment']);
    }

    public function test_unconfigured_default_off_request_keeps_its_existing_not_found_boundary(): void
    {
        $this->identitySetup();
        try {
            (new ProductionFreeGrantHttpIdentity)->forRequest(Request::create('/free-grants'));
            $this->fail('Unconfigured operative identity must remain unavailable.');
        } catch (FreeGrantException $error) {
            $this->assertSame(404, $error->status);
        }
    }

    #[DataProvider('extensions')]
    public function test_retained_request_refuses_callback_parents_and_leaves_before_any_offset_or_string_conversion(string $parent): void
    {
        $this->identitySetup();
        config(['free-grants.operative_enabled' => true, 'free-grants.test_enabled' => false,
            'production-free-grant-identity.enabled' => true, 'production-free-grant-identity.provenance' => 'synthetic_rehearsal',
            'production-free-grant-identity.version' => ProductionFreeGrantIdentityPolicy::VERSION,
            'production-free-grant-identity.purpose' => ProductionFreeGrantIdentityPolicy::PURPOSE]);
        $owner = $this->enrollThroughLocalSmtp();
        Auth::guard('customer')->setUser($owner['user']);
        $request = Request::create('/free-grants');
        $request->setLaravelSession(app('session.store'));
        $marker = ['binding_digest' => $owner['principal']->sessionBindingDigest()];
        $request->session()->put('_production_customer_identity', $marker);
        $request->setUserResolver(fn () => $owner['user']);
        $binding = (new ProductionFreeGrantHttpIdentity)->binding($request);
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
            $binding->proveCurrent();
        } catch (FreeGrantException $error) {
            $refused = $error->status === 403;
        } finally {
            $property->setValue($repository, $before);
            $instances->setValue($this->app, $beforeInstances);
        }
        $this->assertTrue($refused);
        $this->assertSame(0, $callback->calls);
        $this->assertSame($marker, $request->session()->get('_production_customer_identity'));
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
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
