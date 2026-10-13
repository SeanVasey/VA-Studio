<?php

namespace App\Domain\Memberships\Production;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;

/** Captured plain configuration and exact registered capabilities; admission resolves no callbacks. */
final class MembershipAwardConfiguration
{
    private const PARENTS = ['app', 'database', 'production-memberships', 'production-membership-billing', 'production-customer-identity'];

    private const CAPABILITIES = [MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class,
        MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class];

    private Container $application;

    private Repository $repository;

    private array $snapshot;

    private array $capabilities;

    public readonly MembershipPaidInvoiceAuthority $invoices;

    public readonly MembershipPolicyFactsAuthority $policies;

    public function __construct(bool $requireComplete = false)
    {
        $this->application = Container::getInstance();
        $this->repository = $this->application->make('config');
        MembershipException::require(get_class($this->repository) === Repository::class, 'changed_policy');
        // Capture before any capability resolution. Callback changes cannot become the baseline.
        $this->snapshot = $this->values();
        $this->capabilities = $this->sources();
        if ($requireComplete) {
            $this->completeCapabilities();
        }
        $this->invoices = $this->application->make(MembershipPaidInvoiceAuthority::class);
        $this->policies = $this->application->make(MembershipPolicyFactsAuthority::class);
        $this->admit();
    }

    public function admit(): void
    {
        MembershipException::require($this->values() === $this->snapshot && $this->sources() === $this->capabilities, 'changed_policy');
    }

    public static function plainPrimary(PDO $primary): void
    {
        MembershipException::require(in_array(get_class($primary), [PDO::class, Sqlite::class, Mysql::class], true)
            && $primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class], 'changed_primary');
    }

    private function values(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->application);
        MembershipException::require(Container::getInstance() === $this->application && is_array($instances)
            && ($instances['config'] ?? null) === $this->repository, 'changed_policy');
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->repository);
        MembershipException::require(is_array($items), 'changed_policy');
        $snapshot = [];
        $remaining = 10000;
        foreach (self::PARENTS as $parent) {
            MembershipException::require(is_array($items[$parent] ?? null), 'changed_policy');
            $snapshot[$parent] = $this->copy($items[$parent], $remaining);
        }

        return $snapshot;
    }

    private function sources(): array
    {
        $result = [];
        foreach (['instances', 'bindings', 'aliases', 'extenders', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
            $values = (new ReflectionProperty(Container::class, $property))->getValue($this->application);
            MembershipException::require(is_array($values), 'changed_policy');
            foreach (self::CAPABILITIES as $capability) {
                $result[$property][$capability] = $this->descriptor($values[$capability] ?? null);
            }
        }

        return $result;
    }

    /** Plain configuration alone grants nothing. The owned award requires this complete baseline. */
    private function completeCapabilities(): void
    {
        foreach (self::CAPABILITIES as $capability) {
            // This unmounted operation accepts direct registrations, never an untracked alias chain.
            MembershipException::require($this->capabilities['aliases'][$capability] === null, 'capability_alias_refused');
            $instance = $this->capabilities['instances'][$capability];
            $binding = $this->capabilities['bindings'][$capability];
            MembershipException::require($instance !== null ? $instance instanceof $capability : is_array($binding), 'source_capability_absent');
        }
    }

    /** Preserve object identity but detach PHP references inside registration/callback arrays. */
    private function descriptor(mixed $value, int $depth = 0): mixed
    {
        MembershipException::require($depth <= 16, 'changed_policy');
        if (! is_array($value)) {
            return $value;
        }
        $copy = [];
        foreach ($value as $key => $child) {
            $copy[$key] = $this->descriptor($child, $depth + 1);
        }

        return $copy;
    }

    private function copy(mixed $value, int &$remaining, int $depth = 0): mixed
    {
        MembershipException::require(--$remaining >= 0 && $depth <= 32, 'changed_policy');
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = $this->copy($child, $remaining, $depth + 1);
            }

            return $result;
        }
        MembershipException::require($value === null || is_string($value) || is_int($value) || is_bool($value)
            || (is_float($value) && is_finite($value)), 'changed_policy');

        return $value;
    }
}
