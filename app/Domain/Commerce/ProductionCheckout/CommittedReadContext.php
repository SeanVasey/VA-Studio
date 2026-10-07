<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionClass;

/** Captured before source decryption; a receipt may compare this frame but cannot extend its budget. */
final readonly class CommittedReadContext
{
    private const MAXIMUM_SOURCE_NS = 300_000_000_000;

    private function __construct(
        private Connection $connection,
        private PDO $primary,
        private string $driver,
        private string $database,
        private string $prefix,
        private Container $container,
        private Repository $configuration,
        private DatabaseManager $manager,
        private Closure $rawBindings,
        private array $originalBindings,
        private string $configurationHash,
        private int $capturedAtNs,
    ) {}

    public static function capture(CurrentRows $reader): self
    {
        HeldSourceTransaction::requireCurrent($reader);
        $connection = ResolvedConnection::current('committed_read_frame');
        $container = Container::getInstance();
        $raw = Closure::bind(function (): array {
            return [$this->instances['config'] ?? null, $this->instances['db'] ?? null,
                $this->aliases['config'] ?? null, $this->aliases['db'] ?? null];
        }, $container, Container::class);
        CheckoutException::require($raw instanceof Closure, 'committed_read_frame');
        $bindings = $raw();
        $configuration = $bindings[0];
        $manager = $bindings[1];
        CheckoutException::require($configuration instanceof Repository && $configuration::class === Repository::class
            && $manager instanceof DatabaseManager && $manager::class === DatabaseManager::class, 'committed_read_frame');
        $context = new self($connection, $reader->identityPrimary(), $reader->identityDriver(),
            $connection->getDatabaseName(), $connection->getTablePrefix(), $container, $configuration, $manager,
            $raw, $bindings, self::configurationHash($configuration), hrtime(true));
        $context->prove($reader, 1);

        return $context;
    }

    public function deadline(int $originalDeadlineNs): int
    {
        $deadline = min($originalDeadlineNs, $this->capturedAtNs + self::MAXIMUM_SOURCE_NS);
        CheckoutException::require(hrtime(true) < $deadline, 'committed_read_expired');

        return $deadline;
    }

    public function requireReceiptsEnabled(): void
    {
        CheckoutException::require($this->configuration->get('production_checkout.committed_read_receipts_enabled') === true
            && $this->configuration->get('production_checkout.committed_read_receipt_version') === 'production-checkout-committed-read-v1', 'unsupported');
    }

    public function prove(CurrentRows $reader, int $depth): void
    {
        CheckoutException::require(in_array($depth, [0, 1], true)
            && Container::getInstance() === $this->container && DB::getFacadeApplication() === $this->container
            && ($this->rawBindings)() === $this->originalBindings
            && self::configurationHash($this->configuration) === $this->configurationHash, 'committed_read_frame');
        $name = $this->configuration->get('database.default');
        $connection = $this->manager->getConnections()[$name] ?? null;
        $classes = $this->driver === 'mysql' ? [PDO::class, Mysql::class] : [PDO::class, Sqlite::class];
        CheckoutException::require($connection === $this->connection && $connection->getRawPdo() === $this->primary
            && $reader->identityPrimary() === $this->primary && $reader->identityDriver() === $this->driver
            && $connection->getDriverName() === $this->driver && $connection->getDatabaseName() === $this->database
            && $connection->getTablePrefix() === $this->prefix && $connection->transactionLevel() === $depth
            && in_array($this->primary::class, $classes, true) && (new ReflectionClass($this->primary))->isInternal()
            && $this->primary->inTransaction() === ($depth === 1)
            && $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $this->driver
            && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'committed_read_frame');
        if ($this->driver === 'mysql') {
            CheckoutException::require($this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database, 'committed_read_frame');
        }
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    private static function configurationHash(Repository $configuration): string
    {
        return CanonicalJson::hash($configuration->get(['database.default', 'app.key', 'app.previous_keys', 'app.cipher', 'app.env',
            'production_checkout', 'production-customer-identity']));
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_committed_read_context'];
    }
}
