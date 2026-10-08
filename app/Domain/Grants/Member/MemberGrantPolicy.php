<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionProperty;

/** A default-off capability baseline, never approval of facts, originals or artifact readiness. */
final class MemberGrantPolicy
{
    public const MAX_ARTIFACTS = 16;

    public const MAX_ARTIFACT_BYTES = 536870912;

    private Container $container;

    private Repository $repository;

    public function __construct()
    {
        $this->container = Container::getInstance();
        $this->repository = $this->container->make('config');
        MemberGrantException::require(get_class($this->repository) === Repository::class, 'changed_policy');
    }

    public function current(): array
    {
        $configuration = $this->configuration();
        MemberGrantException::require($configuration['enabled'] === true && $configuration['version'] === 1
            && $configuration['family'] === MemberGrantIntent::FAMILY && $configuration['purpose'] === MemberGrantIntent::PURPOSE, 'disabled');
        MemberGrantException::require(in_array($configuration['provenance'], [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true)
            && ($configuration['provenance'] !== IdentityPolicy::REHEARSAL || in_array($configuration['environment'], ['local', 'testing'], true)), 'provenance');
        foreach (['approved_definition_hash', 'approved_profile_hash', 'approved_original_terms_hash'] as $key) {
            MemberGrantException::require(is_string($configuration[$key])
                && preg_match('/\A[a-f0-9]{64}\z/D', $configuration[$key]) === 1, 'facts_absent');
        }
        foreach ([MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class] as $capability) {
            MemberGrantException::require($this->container->bound($capability), 'capability_absent');
            MemberGrantException::require($this->container->make($capability) instanceof $capability, 'capability_absent');
        }
        $this->proveConfiguration($configuration);

        return $configuration;
    }

    public function proveConfiguration(array $expected): void
    {
        MemberGrantException::require($this->configuration() === $expected, 'changed_policy');
    }

    private function configuration(): array
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        MemberGrantException::require(is_array($instances) && Container::getInstance() === $this->container
            && ($instances['config'] ?? null) === $this->repository, 'changed_policy');
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->repository);
        MemberGrantException::require(is_array($items) && is_array($items['member-grants'] ?? null), 'changed_policy');
        $policy = $items['member-grants'];
        $environment = $instances['env'] ?? null;
        MemberGrantException::require(is_bool($policy['enabled'] ?? null) && is_int($policy['version'] ?? null)
            && is_string($policy['family'] ?? null) && is_string($policy['purpose'] ?? null)
            && ($environment === null || is_string($environment)), 'changed_policy');
        $result = array_intersect_key($policy, array_flip(['enabled', 'version', 'family', 'purpose', 'provenance',
            'approved_definition_hash', 'approved_profile_hash', 'approved_original_terms_hash']));
        foreach (['provenance', 'approved_definition_hash', 'approved_profile_hash', 'approved_original_terms_hash'] as $key) {
            MemberGrantException::require(array_key_exists($key, $result) && ($result[$key] === null || is_string($result[$key])), 'changed_policy');
        }

        return [...$result, 'environment' => $environment];
    }
}
