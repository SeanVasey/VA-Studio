<?php

namespace Tests\Feature\ProductionFreeIdentity;

use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\Production\ProductionFreeGrantHttpIdentity;
use App\Domain\Grants\Free\Production\ProductionFreeGrantIdentityPolicy;
use ArrayObject;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\Support\ProductionIdentityFixture;
use Tests\TestCase;

final class ProductionFreeHttpNestedConfigurationCanaryTest extends TestCase
{
    use ProductionIdentityFixture;

    public function test_retained_http_closure_cannot_resolve_a_nested_configuration_callback_after_marker_proof(): void
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
        $repository = app('config');
        $property = new ReflectionProperty(Repository::class, 'items');
        $before = $property->getValue($repository);
        $callback = new ProductionFreeHttpNestedConfigurationCallback($before['app'], $request);
        $items = $before;
        $items['app'] = $callback;
        $property->setValue($repository, $items);
        $refused = $released = false;
        try {
            $binding = (new ProductionFreeGrantHttpIdentity)->binding($request);
            $callback->armed = true;
            $callback->calls = 0;
            $binding->proveCurrent();
            $released = true;
        } catch (FreeGrantException) {
            $refused = true;
        } finally {
            $property->setValue($repository, $before);
        }
        file_put_contents(__DIR__.'/nested-snapshot.json', json_encode([
            'source' => '233f0864028156faeb70c63259910214e47762cb',
            'callbacks' => $callback->calls, 'released' => $released, 'refused' => $refused,
            'marker_retained' => $request->session()->get('_production_customer_identity') === $marker,
            'active_transaction' => DB::connection()->getPdo()->inTransaction(),
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
        $this->assertSame(0, $callback->calls);
        $this->assertSame($marker, $request->session()->get('_production_customer_identity'));
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }
}

final class ProductionFreeHttpNestedConfigurationCallback extends ArrayObject
{
    public bool $armed = false;

    public int $calls = 0;

    public function __construct(array $values, private readonly Request $request)
    {
        parent::__construct($values);
    }

    public function offsetGet(mixed $key): mixed
    {
        if ($this->armed) {
            $this->calls++;
            $this->request->session()->forget('_production_customer_identity');
        }

        return parent::offsetGet($key);
    }
}
