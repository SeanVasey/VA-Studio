<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionProperty;

/** Default-off capability/facts gate. A flag, hash, redirect or synthetic ledger cannot award. */
final class MembershipPolicy
{
    public const VERSION = 'production-membership-period-v1';

    public const FAMILY = 'production-membership-paid-period-v1';

    public const MAX_CREDITS = 1000000;

    public const MAX_EVENTS = 10000;

    public const MAX_PERIODS = 100;

    private Container $container;

    private Repository $repository;

    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->repository = $this->container->make('config');
        MembershipException::require(get_class($this->repository) === Repository::class, 'changed_policy');
    }

    /** Configuration is an internal baseline, never paid invoice or seller-policy proof. */
    public function current(): array
    {
        $configuration = $this->configuration();
        MembershipException::require($configuration['enabled'] === true && $configuration['version'] === self::VERSION, 'disabled');
        MembershipException::require(in_array($configuration['provenance'], [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true)
            && ($configuration['provenance'] !== IdentityPolicy::REHEARSAL || in_array($configuration['environment'], ['local', 'testing'], true))
            // Only the native driver can serve verified evidence; SQLite callbacks are not fully enumerable.
            && ($configuration['provenance'] !== IdentityPolicy::PRODUCTION || $configuration['driver'] === 'mysql'), 'provenance');
        MembershipException::require(is_string($configuration['approved_policy_hash'])
            && preg_match('/\A[a-f0-9]{64}\z/D', $configuration['approved_policy_hash']) === 1, 'policy_facts_absent');
        // No implementation is supplied here. Actual reviewed invoice/policy/eligibility/grant
        // providers are needed before an operative writer can be registered.
        foreach ([MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class,
            MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class] as $capability) {
            MembershipException::require($this->container->bound($capability), 'source_capability_absent');
            MembershipException::require($this->container->make($capability) instanceof $capability, 'source_capability_absent');
        }
        MembershipException::require($this->configuration() === $configuration, 'changed_policy');

        return $configuration;
    }

    public function proveConfiguration(array $expected): void
    {
        MembershipException::require($this->configuration() === $expected, 'changed_policy');
    }

    private function configuration(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        MembershipException::require(is_array($instances) && Container::getInstance() === $this->container
            && ($instances['config'] ?? null) === $this->repository, 'changed_policy');
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->repository);
        MembershipException::require(is_array($items) && is_array($items['production-memberships'] ?? []), 'changed_policy');
        $policy = $items['production-memberships'] ?? [];
        $environment = $instances['env'] ?? null;
        $database = $items['database'] ?? null;
        MembershipException::require(is_array($database) && is_string($database['default'] ?? null)
            && is_array($database['connections'] ?? null) && is_array($database['connections'][$database['default']] ?? null), 'changed_policy');
        $driver = $database['connections'][$database['default']]['driver'] ?? null;
        MembershipException::require(is_bool($policy['enabled'] ?? null)
            && is_string($policy['version'] ?? null)
            && (($policy['provenance'] ?? null) === null || is_string($policy['provenance']))
            && (($policy['approved_policy_hash'] ?? null) === null || is_string($policy['approved_policy_hash']))
            && ($environment === null || is_string($environment)) && is_string($driver), 'changed_policy');

        return ['enabled' => $policy['enabled'] ?? null, 'version' => $policy['version'] ?? null,
            'provenance' => $policy['provenance'] ?? null, 'approved_policy_hash' => $policy['approved_policy_hash'] ?? null,
            'environment' => $environment, 'driver' => $driver];
    }
}
