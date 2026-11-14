<?php

namespace App\Domain\Commerce\ProductionFinancialRetrieval;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialInspectionException;
use App\Domain\Commerce\ProductionFinancialInspection\FinancialInspectionPolicy;
use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Commerce\ProductionTaxCheckout\TaxPaidOriginalCommitDispatcher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use PDO;
use PDOStatement;
use ReflectionClass;
use ReflectionProperty;
use SensitiveParameter;
use Stripe\Stripe;

/** Read loaded native metadata only. This module registers no gateway or runtime binding. */
final readonly class RetrievalPolicy
{
    private const PINS = ['enabled' => true, 'purpose' => 'original-paid-test-sdk-read-v1', 'schema_version' => 1,
        'stripe_sdk_version' => '21.3.2', 'stripe_api_version' => '2026-08-26.dahlia', 'maximum_seconds' => 30];

    private function __construct(private Application $app, private Repository $config,
        private FinancialInspectionPolicy $candidatePolicy, #[SensitiveParameter] private array $snapshot,
        private DatabaseManager $manager, private array $connectionMap, private array $primaryMap) {}

    public static function capture(): self
    {
        try {
            $candidate = FinancialInspectionPolicy::capture();
        } catch (FinancialInspectionException $error) {
            throw new FinancialRetrievalException($error->reason);
        }
        $app = Container::getInstance();
        FinancialRetrievalException::require($app::class === Application::class, 'disabled');
        $instances = self::arrayProperty($app, Container::class, 'instances');
        $config = $instances['config'] ?? null;
        FinancialRetrievalException::require($config instanceof Repository && $config::class === Repository::class, 'disabled');

        $manager = $instances['db'] ?? null;
        FinancialRetrievalException::require($manager instanceof DatabaseManager && $manager::class === DatabaseManager::class);
        $connections = self::arrayProperty($manager, DatabaseManager::class, 'connections');
        $primaries = [];
        foreach ($connections as $name => $connection) {
            $primaries[$name] = self::admitConnection($connection);
        }
        self::admitFacade($app, $manager);

        return new self($app, $config, $candidate, self::read($app, $config), $manager, $connections, $primaries);
    }

    public function prove(): void
    {
        $this->candidatePolicy->prove();
        FinancialRetrievalException::require(Container::getInstance() === $this->app
            && self::read($this->app, $this->config) === $this->snapshot, 'disabled');
        $this->connections();
    }

    public function bindAccount(string $account): void
    {
        $this->prove();
        FinancialRetrievalException::require($this->snapshot[1]['account_id'] === $account);
    }

    /** Request header identity; never exposes the configured credential. */
    public function admitsAuthorization(#[SensitiveParameter] string $header): bool
    {
        return hash_equals('Authorization: Bearer '.$this->snapshot[1]['secret_key'], $header);
    }

    public function requireOutsideTransactions(): void
    {
        $this->prove();
        foreach ($this->connections() as $connection) {
            FinancialRetrievalException::require(is_object($connection)
                && in_array($connection::class, [SQLiteConnection::class, MySqlConnection::class], true));
            $depth = self::raw($connection, Connection::class, 'transactions');
            $pdo = self::raw($connection, Connection::class, 'pdo');
            FinancialRetrievalException::require($depth === 0 && ! $pdo->inTransaction());
        }
    }

    /** Reject an existing cohort without invoking either mint or any source proof/extraction. */
    public function requireUnobserved(CurrentRows $reader): void
    {
        $this->prove();
        $found = false;
        foreach ($this->connections() as $connection) {
            FinancialRetrievalException::require(is_object($connection)
                && in_array($connection::class, [SQLiteConnection::class, MySqlConnection::class], true));
            if (self::raw($connection, Connection::class, 'pdo') === $reader->identityPrimary()) {
                $found = true;
                FinancialRetrievalException::require(self::raw($connection, Connection::class, 'transactions') === 1
                    && $reader->identityPrimary()->inTransaction());
                $events = self::raw($connection, Connection::class, 'events');
                FinancialRetrievalException::require(! $events instanceof OriginalCommitDispatcher
                    && ! $events instanceof TaxPaidOriginalCommitDispatcher
                    && ! $events instanceof CheckoutCommandCommitDispatcher
                    && $events instanceof Dispatcher && $events::class === Dispatcher::class, 'existing_cohort');
            }
        }
        FinancialRetrievalException::require($found);
    }

    private function connections(): array
    {
        $instances = self::arrayProperty($this->app, Container::class, 'instances');
        $manager = $instances['db'] ?? null;
        FinancialRetrievalException::require($manager === $this->manager);
        self::admitFacade($this->app, $manager);
        $connections = self::arrayProperty($manager, DatabaseManager::class, 'connections');
        FinancialRetrievalException::require($connections === $this->connectionMap);
        foreach ($connections as $name => $connection) {
            FinancialRetrievalException::require(self::admitConnection($connection) === $this->primaryMap[$name]);
        }

        return $connections;
    }

    private static function admitFacade(Application $app, DatabaseManager $manager): void
    {
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        FinancialRetrievalException::require((new ReflectionProperty(Facade::class, 'app'))->getValue() === $app
            && is_array($resolved) && ($resolved['db'] ?? null) === $manager);
    }

    private static function admitConnection(mixed $connection): array
    {
        FinancialRetrievalException::require(is_object($connection)
            && in_array($connection::class, [SQLiteConnection::class, MySqlConnection::class], true));
        $config = self::raw($connection, Connection::class, 'config');
        $database = self::raw($connection, Connection::class, 'database');
        $prefix = self::raw($connection, Connection::class, 'tablePrefix');
        $primary = self::raw($connection, Connection::class, 'pdo');
        FinancialRetrievalException::require(is_array($config) && is_string($config['driver'] ?? null)
            && is_string($config['name'] ?? null) && is_string($database) && is_string($prefix)
            && is_int(self::raw($connection, Connection::class, 'transactions'))
            && $primary instanceof PDO && in_array($primary::class, [PDO::class, 'Pdo\\Mysql', 'Pdo\\Sqlite'], true)
            && (new ReflectionClass($primary))->isInternal());
        // Native methods are reached only after literal class/internal admission.
        FinancialRetrievalException::require($primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $primary->getAttribute(PDO::ATTR_DRIVER_NAME) === $config['driver']
            && $primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION);

        return [$primary, $config, $database, $prefix];
    }

    private static function read(Application $app, Repository $config): array
    {
        $instances = self::arrayProperty($app, Container::class, 'instances');
        $aliases = self::arrayProperty($app, Container::class, 'aliases');
        FinancialRetrievalException::require(($instances['config'] ?? null) === $config
            && ! isset($aliases['config']) && ! isset($aliases['db']), 'disabled');
        $items = self::arrayProperty($config, Repository::class, 'items');
        $policy = $items['production-financial-retrieval'] ?? null;
        FinancialRetrievalException::require(is_array($policy) && count($policy) === count(self::PINS), 'disabled');
        foreach (self::PINS as $key => $value) {
            FinancialRetrievalException::require(($policy[$key] ?? null) === $value, 'disabled');
        }
        $payments = $items['payments'] ?? null;
        FinancialRetrievalException::require(is_array($payments) && is_array($payments['stripe'] ?? null), 'disabled');
        $stripe = $payments['stripe'];
        foreach ($stripe as $value) {
            FinancialRetrievalException::require($value === null || is_scalar($value), 'disabled');
        }
        FinancialRetrievalException::require(($stripe['mode'] ?? null) === 'test'
            && is_string($stripe['account_id'] ?? null) && preg_match('/\Aacct_[A-Za-z0-9]{1,64}\z/D', $stripe['account_id']) === 1
            && is_string($stripe['secret_key'] ?? null) && preg_match('/\Ask_test_[A-Za-z0-9]{8,200}\z/D', $stripe['secret_key']) === 1
            && Stripe::$accountId === null && Stripe::$verifySslCerts === true, 'disabled');

        return [$policy, $stripe];
    }

    private static function arrayProperty(object $object, string $class, string $name): array
    {
        $value = self::raw($object, $class, $name);
        FinancialRetrievalException::require(is_array($value), 'disabled');

        return $value;
    }

    public static function raw(object $object, string $class, string $name): mixed
    {
        return (new ReflectionProperty($class, $name))->getRawValue($object);
    }

    public function __serialize(): never
    {
        throw new \LogicException('Internal financial scope cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Internal financial scope cannot be reconstructed.');
    }

    public function __debugInfo(): array
    {
        return ['purpose' => 'test_sdk_read_only'];
    }
}
