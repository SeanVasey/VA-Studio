<?php

namespace Tests\Feature;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommittedReadContext;
use App\Domain\Commerce\ProductionCheckout\HeldSourceTransaction;
use App\Domain\Commerce\ProductionCheckout\ResolvedConnection;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityMetadataAdmission;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use ArrayObject;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Stringable;
use Tests\TestCase;
use Throwable;

/** Current App entrypoints, real Laravel connection/PDO, no replaced App classes or frozen fixtures. */
class ProductionCheckoutMetadataAdmissionTest extends TestCase
{
    private Connection $connection;

    private PDO $primary;

    private CurrentRows $reader;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
            'production-customer-identity.historical_receipts_enabled' => true,
            'production-customer-identity.historical_receipts_version' => IdentityHistoricalCommittedReceipt::VERSION]);
        $this->connection = DB::connection();
        $this->primary = $this->connection->getPdo();
        $this->reader = new CurrentRows($this->primary, $this->connection->getDriverName());
    }

    public static function captures(): array
    {
        $cases = [];
        foreach (['facade', 'instances', 'aliases', 'connections', 'config', 'name', 'database', 'prefix', 'connection', 'peer'] as $surface) {
            foreach (['held', 'committed', 'witness'] as $target) {
                $cases[$target.'-'.$surface] = [$target, $surface];
            }
        }

        return $cases;
    }

    #[DataProvider('captures')]
    public function test_unwrapped_capture_refuses_before_metadata_side_effects(string $target, string $surface): void
    {
        $this->connection->beginTransaction();
        [$sentinel, $restore] = $this->replace($surface);
        $failure = null;
        try {
            match ($target) {
                'held' => HeldSourceTransaction::capture($this->reader),
                'committed' => CommittedReadContext::capture($this->reader),
                'witness' => IdentityOriginalCommitWitness::capture($this->reader, hrtime(true) + 30_000_000_000),
            };
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $restore();
            $this->connection->rollBack();
        }
        $this->assertSame(0, $sentinel->callbacks, 'Raw admission must precede every offset/getter/coercion callback.');
        $this->assertInstanceOf($target === 'witness' ? IdentityException::class : CheckoutException::class, $failure);
        $this->assertSame($this->primary, $this->connection->getRawPdo());
        $this->assertSame(0, $this->connection->transactionLevel());
    }

    public static function reuses(): array
    {
        $cases = [];
        foreach (['facade', 'config', 'peer'] as $surface) {
            foreach (['committed', 'witness'] as $target) {
                $cases[$target.'-'.$surface] = [$target, $surface];
            }
        }

        return $cases;
    }

    #[DataProvider('reuses')]
    public function test_late_metadata_replacement_refuses_before_reuse_callbacks(string $target, string $surface): void
    {
        $this->connection->beginTransaction();
        $proof = $target === 'committed' ? CommittedReadContext::capture($this->reader)
            : IdentityOriginalCommitWitness::capture($this->reader, hrtime(true) + 30_000_000_000);
        [$sentinel, $restore] = $this->replace($surface);
        $failure = null;
        try {
            $target === 'committed' ? $proof->prove($this->reader, 1) : $proof->assertHeld();
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $restore();
            $this->connection->rollBack();
        }
        $this->assertSame(0, $sentinel->callbacks);
        $this->assertInstanceOf($target === 'witness' ? IdentityException::class : CheckoutException::class, $failure);
    }

    public function test_canonical_held_and_committed_context_keep_original_pdo_and_deadline(): void
    {
        $this->connection->beginTransaction();
        try {
            $held = HeldSourceTransaction::capture($this->reader);
            $held->prove($this->reader);
            $context = $held->committedReadContext($this->reader);
            $original = hrtime(true) + 10_000_000_000;
            $deadline = $context->deadline($original);
            $context->prove($this->reader, 1);
            $this->assertSame($original, $deadline);
            $this->assertSame($deadline, $context->deadline($original));
            $this->assertSame($this->connection, $context->connection());
            $this->connection->commit();
            $context->prove($this->reader, 0);
            $this->assertSame($deadline, $context->deadline($original));
            $this->assertSame($this->primary, $this->connection->getRawPdo());
        } finally {
            if ($this->connection->transactionLevel() !== 0) {
                $this->connection->rollBack();
            }
        }
    }

    public function test_metadata_selector_does_not_relax_existing_owned_primary_admission(): void
    {
        $this->connection->beginTransaction();
        try {
            $this->assertSame($this->connection, ResolvedConnection::current());
            IdentityMetadataAdmission::admit($this->primary);
            $failure = null;
            try {
                IdentityMetadataAdmission::admit();
            } catch (IdentityException $error) {
                $failure = $error;
            }
            $this->assertInstanceOf(IdentityException::class, $failure);
            $deadline = hrtime(true) + 10_000_000_000;
            $witness = IdentityOriginalCommitWitness::capture($this->reader, $deadline);
            $witness->assertHeld();
            $this->assertTrue($witness->matches($this->reader, $deadline));
            $this->assertFalse($witness->matches($this->reader, $deadline + 1));
        } finally {
            $this->connection->rollBack();
        }
    }

    public function test_plain_metadata_does_not_adopt_a_foreign_reader_or_unowned_depth(): void
    {
        $foreign = new CurrentRows(new PDO('sqlite::memory:'), 'sqlite');
        foreach ([false, true] as $held) {
            if ($held) {
                $this->connection->beginTransaction();
            }
            try {
                $failure = null;
                try {
                    HeldSourceTransaction::capture($held ? $foreign : $this->reader);
                } catch (CheckoutException $error) {
                    $failure = $error;
                }
                $this->assertInstanceOf(CheckoutException::class, $failure);
                $this->assertSame($this->primary, $this->connection->getRawPdo());
            } finally {
                if ($held) {
                    $this->connection->rollBack();
                }
            }
        }
    }

    public function test_lazy_primary_refuses_without_running_its_connector(): void
    {
        $property = new ReflectionProperty(Connection::class, 'pdo');
        $before = $property->getRawValue($this->connection);
        $callbacks = 0;
        $property->setValue($this->connection, function () use (&$callbacks) {
            $callbacks++;

            return $this->primary;
        });
        $failure = null;
        try {
            ResolvedConnection::current();
        } catch (Throwable $error) {
            $failure = $error;
        } finally {
            $property->setValue($this->connection, $before);
        }
        $this->assertInstanceOf(CheckoutException::class, $failure);
        $this->assertSame(0, $callbacks);
        $this->assertSame($this->primary, $this->connection->getRawPdo());
    }

    /** Restore framework metadata before any assertion/rollback/teardown can resolve it. */
    private function replace(string $surface): array
    {
        $manager = DB::getFacadeRoot();
        $name = $this->connection->getName();
        $sentinel = new CheckoutMetadataOffsetSentinel;
        if ($surface === 'facade') {
            $property = new ReflectionProperty(Facade::class, 'resolvedInstance');
            $object = null;
        } elseif (in_array($surface, ['instances', 'aliases'], true)) {
            $property = new ReflectionProperty(Container::class, $surface);
            $object = $this->app;
        } elseif (in_array($surface, ['connections', 'connection', 'peer'], true)) {
            $property = new ReflectionProperty(DatabaseManager::class, 'connections');
            $object = $manager;
        } else {
            $property = new ReflectionProperty(Connection::class, match ($surface) {
                'name', 'config' => 'config', 'database' => 'database', 'prefix' => 'tablePrefix',
            });
            $object = $this->connection;
        }
        $before = $property->getRawValue($object);
        if (in_array($surface, ['connection', 'peer'], true)) {
            $config = (new ReflectionProperty(Connection::class, 'config'))->getRawValue($this->connection);
            $sentinel = new CheckoutMetadataGetterSentinel($this->primary, '', '', $config);
            $after = $before;
            $after[$surface === 'peer' ? 'metadata_peer' : $name] = $sentinel;
        } elseif ($surface === 'name') {
            $after = $before;
            $after['name'] = $sentinel;
        } elseif (in_array($surface, ['database', 'prefix'], true)) {
            $after = $sentinel;
        } else {
            $sentinel->exchangeArray($before);
            $after = $sentinel;
        }
        $property->setValue($object, $after);

        return [$sentinel, static fn () => $property->setValue($object, $before)];
    }
}

final class CheckoutMetadataOffsetSentinel extends ArrayObject implements Stringable
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

    public function __toString(): string
    {
        $this->callbacks++;

        return 'metadata-sentinel';
    }
}

final class CheckoutMetadataGetterSentinel extends Connection
{
    public int $callbacks = 0;

    public function getRawPdo()
    {
        $this->callbacks++;

        return parent::getRawPdo();
    }
}
