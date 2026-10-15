<?php

namespace App\Domain\Commerce\ProductionFinancialRetrieval;

use App\Domain\Commerce\ProductionCheckout\CheckoutCommandCommitDispatcher;
use App\Domain\Commerce\ProductionCheckout\OriginalCommitDispatcher;
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
use PDO;
use ReflectionProperty;
use SensitiveParameter;
use Stripe\Stripe;

/** Read loaded native metadata only. This module registers no gateway or runtime binding. */
final readonly class RetrievalPolicy
{
    private const PINS = ['enabled' => true, 'purpose' => 'original-paid-test-sdk-read-v1', 'schema_version' => 1,
        'stripe_sdk_version' => '21.3.2', 'stripe_api_version' => '2026-08-26.dahlia', 'maximum_seconds' => 30];

    private function __construct(private Application $app, private Repository $config,
        private FinancialInspectionPolicy $candidatePolicy, #[SensitiveParameter] private array $snapshot) {}

    public static function capture(): self
    {
        $candidate = FinancialInspectionPolicy::capture();
        $app = Container::getInstance();
        FinancialRetrievalException::require($app::class === Application::class, 'disabled');
        $instances = self::arrayProperty($app, Container::class, 'instances');
        $config = $instances['config'] ?? null;
        FinancialRetrievalException::require($config instanceof Repository && $config::class === Repository::class, 'disabled');

        return new self($app, $config, $candidate, self::read($app, $config));
    }

    public function prove(): void
    {
        $this->candidatePolicy->prove();
        FinancialRetrievalException::require(Container::getInstance() === $this->app
            && self::read($this->app, $this->config) === $this->snapshot, 'disabled');
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
            FinancialRetrievalException::require($depth === 0 && $pdo instanceof PDO && ! $pdo->inTransaction());
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
        FinancialRetrievalException::require($manager instanceof DatabaseManager && $manager::class === DatabaseManager::class);

        return self::arrayProperty($manager, DatabaseManager::class, 'connections');
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
