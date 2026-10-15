<?php

namespace App\Domain\Commerce\ProductionTaxCheckout;

use App\Domain\Commerce\ProductionCheckout\CheckoutException;
use App\Domain\Commerce\ProductionCheckout\CommittedReadContext;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use LogicException;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;

/** Tax-specific literal birth purpose. The original generic context is attached once, never recaptured. */
final class TaxPaidReadContext
{
    public const VERSION = 'production-tax-checkout-committed-read-v2';

    private ?CommittedReadContext $generic = null;

    private readonly TaxPaidReadFrame $frame;

    private readonly Connection $connection;

    private readonly PDO $primary;

    private readonly string $driver;

    private readonly string $database;

    private readonly Repository $configuration;

    private readonly array $policy;

    private readonly Container $container;

    private ?TaxPaidOriginalCommitDispatcher $observer = null;

    private ?FreshTaxCheckoutPolicy $fresh = null;

    private function __construct(private readonly CurrentRows $reader, private readonly int $bornAtNs)
    {
        $this->container = Container::getInstance();
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        $configuration = $instances['config'] ?? null;
        $manager = $instances['db'] ?? null;
        CheckoutException::require($configuration instanceof Repository && $configuration::class === Repository::class
            && $manager instanceof DatabaseManager && $manager::class === DatabaseManager::class, 'committed_read_frame');
        $this->configuration = $configuration;
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($configuration);
        CheckoutException::require(is_array($items) && is_array($items['database'] ?? null), 'committed_read_frame');
        $this->policy = $items['production-tax-checkout'];
        self::plain($this->policy);
        CheckoutException::require(($this->policy['committed_read_receipts_enabled'] ?? null) === true
            && ($this->policy['committed_read_receipt_version'] ?? null) === self::VERSION, 'unsupported');
        $name = $items['database']['default'] ?? null;
        $connection = is_string($name) ? ($manager->getConnections()[$name] ?? null) : null;
        CheckoutException::require($connection instanceof Connection, 'committed_read_frame');
        $this->connection = $connection;
        $this->primary = $reader->identityPrimary();
        $this->driver = $reader->identityDriver();
        $this->database = $connection->getDatabaseName();
        $this->proveNative(1);
        $this->frame = TaxPaidReadFrame::capture($connection, $this->primary);
    }

    /** The source calls this before any decryption/policy callback. Disabled births mint no capability. */
    public static function birth(CurrentRows $reader, int $bornAtNs): ?self
    {
        $container = Container::getInstance();
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
        $configuration = $instances['config'] ?? null;
        CheckoutException::require($configuration instanceof Repository && $configuration::class === Repository::class, 'committed_read_frame');
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($configuration);
        CheckoutException::require(is_array($items) && is_array($items['production-tax-checkout'] ?? null), 'committed_read_frame');
        if (($items['production-tax-checkout']['committed_read_receipts_enabled'] ?? null) !== true) {
            return null;
        }

        return new self($reader, $bornAtNs);
    }

    public function attach(CommittedReadContext $context): void
    {
        CheckoutException::require($this->generic === null && $context->connection() === $this->connection, 'committed_read_frame');
        $this->generic = $context;
        // Callback-capable policy work is deliberately after the literal birth/older markers.
        $this->fresh = FreshTaxCheckoutPolicy::capture();
        $this->prove(1);
    }

    public function deadline(int $originalDeadlineNs): int
    {
        CheckoutException::require($this->generic !== null, 'committed_read_frame');

        return min($this->generic->deadline($originalDeadlineNs), $this->bornAtNs + 300_000_000_000);
    }

    public function prove(int $depth): void
    {
        $this->proveNative($depth);
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->configuration);
        CheckoutException::require(Container::getInstance() === $this->container && ($instances['config'] ?? null) === $this->configuration
            && is_array($items) && ($items['production-tax-checkout'] ?? null) === $this->policy
            && hrtime(true) < $this->bornAtNs + 300_000_000_000 && $this->generic !== null, 'committed_read_frame');
        $this->generic->prove($this->reader, $depth);
        $this->fresh?->prove();
        $this->proveNative($depth);
    }

    /** Depth0 guard precedes EVERY closed table read, including unbroken frozen identity entries. */
    private function proveNative(int $depth): void
    {
        $classes = $this->driver === 'mysql' ? [PDO::class, 'Pdo\\Mysql'] : [PDO::class, 'Pdo\\Sqlite'];
        CheckoutException::require(in_array($depth, [0, 1], true) && in_array($this->driver, ['mysql', 'sqlite'], true)
            && in_array($this->primary::class, $classes, true) && (new ReflectionClass($this->primary))->isInternal()
            && $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $this->driver
            && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION
            && $this->reader->identityPrimary() === $this->primary && $this->reader->identityDriver() === $this->driver
            && $this->connection->getRawPdo() === $this->primary && $this->connection->getDatabaseName() === $this->database
            && $this->connection->transactionLevel() === $depth && $this->primary->inTransaction() === ($depth === 1), 'committed_read_frame');
        if ($depth === 0 && $this->driver === 'mysql') {
            CheckoutException::require((int) $this->primary->query('SELECT @@session.autocommit')->fetchColumn() === 1, 'committed_read_frame');
        }
    }

    public function held(callable $operation): mixed
    {
        $this->prove(1);
        $this->frame->proveHeld();
        $wait = $this->driver === 'mysql' ? (int) $this->primary->query('SELECT @@session.innodb_lock_wait_timeout')->fetchColumn() : null;
        try {
            if ($wait !== null) {
                $this->primary->exec('SET SESSION innodb_lock_wait_timeout = '.min($wait, 5));
            }

            return $operation();
        } catch (\PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw new CheckoutException('contention', 503);
            }
            throw $error;
        } finally {
            if ($wait !== null) {
                $this->primary->exec('SET SESSION innodb_lock_wait_timeout = '.$wait);
            }
        }
    }

    public function proveHeld(): void
    {
        $this->prove(1);
        $this->frame->proveHeld();
    }

    public function closed(Closure $operation): mixed
    {
        $this->prove(0);
        $result = $operation();
        $this->prove(0);

        return $result;
    }

    public function bindObserver(TaxPaidOriginalCommitDispatcher $observer): void
    {
        CheckoutException::require($this->observer === null, 'committed_read_frame');
        $this->observer = $observer;
    }

    public function sealTerminal(): void
    {
        $this->prove(1);
        $this->frame->sealTerminal();
        $this->prove(1);
    }

    public function abortOwnedFrame(): void
    {
        $this->observer?->poison();
        $this->frame->abort();
        $this->observer?->releaseOwned();
    }

    public function reader(): CurrentRows
    {
        return $this->reader;
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function database(): string
    {
        return $this->database;
    }

    public function primary(): PDO
    {
        return $this->primary;
    }

    public function driver(): string
    {
        return $this->driver;
    }

    private static function plain(array $values): void
    {
        foreach ($values as $value) {
            CheckoutException::require(is_array($value) || is_scalar($value) || $value === null, 'committed_read_frame');
            if (is_array($value)) {
                self::plain($value);
            }
        }
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new LogicException('Tax paid read contexts cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Tax paid read contexts cannot be deserialized.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_tax_paid_read_context'];
    }
}
