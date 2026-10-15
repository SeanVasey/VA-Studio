<?php

namespace Tests\Feature\ProductionIdentityAdapters;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use ArrayObject;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;
use Throwable;

class ProductionFeatureMetadataAdmissionTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
        FeatureActorIdCast::$calls = 0;
        FeatureFacadeCache::$calls = 0;
        FeaturePeerConnection::$calls = 0;
    }

    public function test_custom_actor_id_cast_is_refused_before_eloquent_executes_it(): void
    {
        $identity = $this->featureIdentity();
        $actor = $identity->actor();
        $actor->mergeCasts(['id' => FeatureActorIdCast::class]);
        $error = null;
        try {
            (new ProductionCustomerAccess)->source($actor);
        } catch (Throwable $caught) {
            $error = $caught;
        }
        $this->assertSame(0, FeatureActorIdCast::$calls);
        $this->assertInstanceOf(IdentityException::class, $error);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_nonarray_facade_cache_is_refused_before_offset_callbacks(): void
    {
        $identity = $this->featureIdentity();
        $property = new ReflectionProperty(Facade::class, 'resolvedInstance');
        $original = $property->getValue();
        $property->setValue(new FeatureFacadeCache($original));
        $error = null;
        try {
            (new ProductionCustomerAccess)->source($identity->actor());
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $property->setValue($original);
        }
        $this->assertSame(0, FeatureFacadeCache::$calls);
        $this->assertInstanceOf(IdentityException::class, $error);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public function test_peer_connection_subclass_is_refused_before_its_raw_pdo_method(): void
    {
        $this->featureIdentity();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $manager = app('db');
        $property = new ReflectionProperty(DatabaseManager::class, 'connections');
        $original = $property->getValue($manager);
        $peer = new FeaturePeerConnection(new PDO('sqlite::memory:'), ':memory:', '',
            ['driver' => 'sqlite', 'database' => ':memory:', 'name' => 'metadata_peer']);
        $property->setValue($manager, [...$original, 'metadata_peer' => $peer]);
        $frame = null;
        $error = null;
        try {
            $frame = IdentityCommittedFrame::begin(new CurrentRows($primary, $connection->getDriverName()), hrtime(true) + 5_000_000_000);
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $frame?->close();
            $property->setValue($manager, $original);
        }
        $this->assertSame(0, FeaturePeerConnection::$calls);
        $this->assertInstanceOf(IdentityException::class, $error);
        $this->assertFalse($primary->inTransaction());
    }

    public function test_standard_actor_native_primary_and_idle_standard_peer_keep_original_positive_proofs(): void
    {
        $identity = $this->featureIdentity();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $manager = app('db');
        $property = new ReflectionProperty(DatabaseManager::class, 'connections');
        $original = $property->getValue($manager);
        $peer = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '',
            ['driver' => 'sqlite', 'database' => ':memory:', 'name' => 'metadata_peer']);
        $property->setValue($manager, [...$original, 'metadata_peer' => $peer]);
        $frame = null;
        try {
            $proof = (new ProductionCustomerAccess)->current($identity->principal(), $identity->actor());
            $this->assertSame($identity->principal()->userId, (int) $proof['user']['id']);
            $frame = IdentityCommittedFrame::begin(new CurrentRows($primary, $connection->getDriverName()), hrtime(true) + 5_000_000_000);
            $this->assertTrue($primary->inTransaction());
            $this->assertFalse($peer->getRawPdo()->inTransaction());
            $frame->finish();
            $this->assertFalse($primary->inTransaction());
            $this->assertSame($primary, $connection->getRawPdo());
            $this->assertSame(0, $connection->transactionLevel());
        } finally {
            $frame?->close();
            $property->setValue($manager, $original);
        }
    }

    public static function resolverMaps(): array
    {
        return [['contextual'], ['abstractAliases'], ['deferredServices'], ['nested_contextual']];
    }

    #[DataProvider('resolverMaps')]
    public function test_reached_resolver_maps_are_refused_before_application_offsets(string $name): void
    {
        $identity = $this->featureIdentity();
        $app = app();
        $property = new ReflectionProperty($name === 'deferredServices' ? Application::class : Container::class,
            $name === 'nested_contextual' ? 'contextual' : $name);
        $original = $property->getRawValue($app);
        $stackProperty = new ReflectionProperty(Container::class, 'buildStack');
        $stack = $stackProperty->getRawValue($app);
        if ($name === 'nested_contextual') {
            $property->setValue($app, [...$original, 'FeatureMetadataScope' => new FeatureFacadeCache([])]);
            $stackProperty->setValue($app, ['FeatureMetadataScope']);
        } else {
            $property->setValue($app, new FeatureFacadeCache($original));
        }
        $error = null;
        try {
            (new ProductionCustomerAccess)->source($identity->actor());
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            $property->setValue($app, $original);
            $stackProperty->setValue($app, $stack);
        }
        $this->assertSame(0, FeatureFacadeCache::$calls);
        $this->assertInstanceOf(IdentityException::class, $error);
        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse(DB::connection()->getPdo()->inTransaction());
    }

    public static function earlyLocks(): array
    {
        return [['customer_lock'], ['feature_lock'], ['feature_committed']];
    }

    #[DataProvider('earlyLocks')]
    public function test_early_feature_lock_metadata_is_admitted_before_facade_or_policy(string $phase): void
    {
        $identity = $this->featureIdentity();
        $connection = DB::connection();
        $primary = $connection->getPdo();
        $reader = new CurrentRows($primary, $connection->getDriverName());
        $frame = null;
        if ($phase === 'feature_committed') {
            $frame = IdentityCommittedFrame::begin($reader, hrtime(true) + 5_000_000_000);
        } else {
            $connection->beginTransaction();
        }
        $target = $phase === 'customer_lock' ? null : app();
        $property = new ReflectionProperty($target === null ? Facade::class : Container::class,
            $target === null ? 'resolvedInstance' : 'contextual');
        $original = $target === null ? $property->getValue() : $property->getRawValue($target);
        if ($target === null) {
            $property->setValue(new FeatureFacadeCache($original));
        } else {
            $property->setValue($target, new FeatureFacadeCache($original));
        }
        $error = null;
        try {
            match ($phase) {
                'customer_lock' => (new ProductionCustomerAccess)->lock($identity->principal(), $identity->actor(), $reader),
                'feature_lock' => (new ProductionAccountFeatureAccess)->lock($identity, $reader),
                'feature_committed' => (new ProductionAccountFeatureAccess)->lockCommitted($identity, $frame, []),
            };
        } catch (Throwable $caught) {
            $error = $caught;
        } finally {
            if ($target === null) {
                $property->setValue($original);
            } else {
                $property->setValue($target, $original);
            }
        }
        try {
            $this->assertSame(0, FeatureFacadeCache::$calls);
            $this->assertInstanceOf(IdentityException::class, $error);
            $this->assertTrue($primary->inTransaction());
            $this->assertSame($phase === 'feature_committed' ? 0 : 1, $connection->transactionLevel());
        } finally {
            if ($frame !== null) {
                $frame->close();
            } else {
                $connection->rollBack();
            }
        }
    }
}

final class FeatureActorIdCast implements CastsAttributes
{
    public static int $calls = 0;

    public function get(Model $model, string $key, mixed $value, array $attributes): int
    {
        self::$calls++;

        return (int) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return (int) $value;
    }
}

final class FeatureFacadeCache extends ArrayObject
{
    public static int $calls = 0;

    public function offsetExists(mixed $key): bool
    {
        self::$calls++;

        return parent::offsetExists($key);
    }

    public function offsetGet(mixed $key): mixed
    {
        self::$calls++;

        return parent::offsetGet($key);
    }
}

final class FeaturePeerConnection extends SQLiteConnection
{
    public static int $calls = 0;

    public function getRawPdo()
    {
        self::$calls++;

        return parent::getRawPdo();
    }
}
