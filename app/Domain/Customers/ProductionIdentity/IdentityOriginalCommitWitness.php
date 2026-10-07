<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use SplObjectStorage;

/** Cooperates with ONE producer-owned original-commit observer. It is not a financial source proof. */
final class IdentityOriginalCommitWitness implements JsonSerializable
{
    private string $phase = 'held';

    private SplObjectStorage $receipts;

    private readonly DatabaseManager $manager;

    private readonly Connection $connection;

    private readonly Container $container;

    private readonly Repository $config;

    private readonly PDO $primary;

    private readonly string $driver;

    private readonly string $schema;

    private readonly string $databaseName;

    private readonly string $name;

    private readonly array $configuration;

    private function __construct(private readonly CurrentRows $reader, private readonly int $originalDeadlineNs)
    {
        $this->primary = $reader->identityPrimary();
        $this->driver = $reader->identityDriver();
        $this->statementAdmission();
        $this->manager = DB::getFacadeRoot();
        $this->connection = $this->manager->connection();
        $this->container = app();
        $this->config = config();
        $this->schema = $this->driver === 'sqlite' ? 'main' : $this->connection->getDatabaseName();
        $this->databaseName = $this->connection->getDatabaseName();
        $this->name = $this->connection->getName();
        $this->configuration = $this->configuration();
        $this->receipts = new SplObjectStorage;
        if ($originalDeadlineNs <= hrtime(true) || $originalDeadlineNs > hrtime(true) + 300_000_000_000) {
            throw new IdentityException('historical_commit_required');
        }
        $this->assertHeld();
    }

    public static function capture(CurrentRows $reader, int $originalDeadlineNs): self
    {
        return new self($reader, $originalDeadlineNs);
    }

    public function register(IdentityHistoricalCommittedReceipt $receipt): void
    {
        $this->assertHeld();
        if (count($this->receipts) >= 2 || $this->receipts->contains($receipt)
            || ! $receipt->belongsTo($this, $this->reader, $this->originalDeadlineNs)) {
            throw new IdentityException('historical_commit_required');
        }
        $this->receipts->attach($receipt, false);
    }

    public function sealed(IdentityHistoricalCommittedReceipt $receipt): void
    {
        $this->assertHeld();
        if (! $this->receipts->contains($receipt) || $this->receipts[$receipt] !== false) {
            throw new IdentityException('historical_commit_required');
        }
        $this->receipts[$receipt] = true;
    }

    /** Producer calls only after its original anchor and full raw financial/source fence. No callbacks follow. */
    public function prepareOriginalCommit(): void
    {
        $this->assertHeld();
        if (count($this->receipts) < 1) {
            throw new IdentityException('historical_commit_required');
        }
        foreach ($this->receipts as $receipt) {
            if ($this->receipts[$receipt] !== true) {
                throw new IdentityException('historical_commit_required');
            }
        }
        $this->phase = 'prepared';
    }

    /** ONE producer observer certifies the matching positive framework commit after all existing callbacks. */
    public function observeOriginalPositiveCommit(): void
    {
        if ($this->phase !== 'prepared') {
            throw new IdentityException('historical_commit_required');
        }
        $this->phase = 'invalid';
        $this->context(0);
        $this->phase = 'committed';
    }

    public function assertHeld(): void
    {
        if ($this->phase !== 'held') {
            throw new IdentityException('historical_commit_required');
        }
        $this->context(1);
    }

    public function assertClosed(): void
    {
        if ($this->phase !== 'committed') {
            throw new IdentityException('historical_commit_required');
        }
        $this->context(0);
    }

    /** Plain identity reads may run while sealing the original held frame or after its observed commit. */
    public function assertReading(): void
    {
        $this->phase === 'held' ? $this->assertHeld() : $this->assertClosed();
    }

    public function reader(): CurrentRows
    {
        $this->assertReading();

        return $this->reader;
    }

    public function matches(CurrentRows $reader, int $deadlineNs): bool
    {
        return $reader === $this->reader && $deadlineNs === $this->originalDeadlineNs;
    }

    public function schema(): string
    {
        $this->assertReading();

        return $this->schema;
    }

    public function invalidate(): void
    {
        $this->phase = 'invalid';
    }

    private function context(int $depth): void
    {
        $this->statementAdmission();
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $instances = $this->property(Container::class, 'instances', $this->container);
        $connections = $this->property(DatabaseManager::class, 'connections', $this->manager);
        if (Container::getInstance() !== $this->container || (new ReflectionProperty(Facade::class, 'app'))->getValue() !== $this->container
            || ($resolved['db'] ?? null) !== $this->manager || ($instances['db'] ?? null) !== $this->manager
            || ($instances['config'] ?? null) !== $this->config || ($connections[$this->name] ?? null) !== $this->connection
            || $this->configuration() !== $this->configuration || hrtime(true) >= $this->originalDeadlineNs
            || ! in_array($this->driver, ['sqlite', 'mysql'], true) || $this->connection->getRawPdo() !== $this->primary
            || $this->connection->getDatabaseName() !== $this->databaseName || $this->connection->getName() !== $this->name
            || $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->driver
            || $this->connection->getDriverName() !== $this->driver || $this->connection->transactionLevel() !== $depth
            || $this->primary->inTransaction() !== ($depth === 1)) {
            throw new IdentityException('historical_commit_required');
        }
        foreach ($connections as $connection) {
            $raw = $connection->getRawPdo();
            if (! $raw instanceof PDO || ($connection !== $this->connection && ($connection->transactionLevel() !== 0 || $raw->inTransaction()))) {
                throw new IdentityException('historical_commit_required');
            }
        }
        if ($this->driver === 'mysql' && ($this->connection->getDatabaseName() !== $this->schema
            || $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->schema)) {
            throw new IdentityException('historical_commit_required');
        }
    }

    private function statementAdmission(): void
    {
        $classes = match ($this->driver) {
            'sqlite' => [PDO::class, 'Pdo\\Sqlite'],
            'mysql' => [PDO::class, 'Pdo\\Mysql'],
            default => [],
        };
        if (! in_array($this->primary::class, $classes, true) || ! (new ReflectionClass($this->primary))->isInternal()
            || $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) !== [PDOStatement::class]) {
            throw new IdentityException('historical_plain_statement_required');
        }
    }

    private function configuration(): array
    {
        $items = $this->property(Repository::class, 'items', $this->config);
        $identity = $items['production-customer-identity'] ?? [];
        if (($identity['historical_receipts_enabled'] ?? null) !== true
            || ($identity['historical_receipts_version'] ?? null) !== IdentityHistoricalCommittedReceipt::VERSION
            || ! is_string($items['app']['key'] ?? null) || $items['app']['key'] === '') {
            throw new IdentityException('historical_commit_required');
        }

        return ['key' => $items['app']['key'], 'identity' => $identity, 'default' => $items['database']['default'] ?? null];
    }

    private function property(string $class, string $name, object $object): mixed
    {
        return (new ReflectionProperty($class, $name))->getValue($object);
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new IdentityException('historical_commit_required');
    }

    public function jsonSerialize(): never
    {
        throw new IdentityException('historical_commit_required');
    }

    public function __debugInfo(): array
    {
        return ['original_commit_witness' => true];
    }
}
