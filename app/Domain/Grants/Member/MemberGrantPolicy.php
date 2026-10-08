<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionFunction;
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
        $environment = $this->environment($instances);
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

    /**
     * Laravel binds `env` through Container::offsetSet as `fn () => $value`, never as an instance.
     * Read that captured value by reflection instead of resolving the binding, and refuse any other
     * binding: resolving it would run a callback. An instance, if one is ever set, keeps precedence
     * exactly as the container gives it.
     */
    private function environment(array $instances): mixed
    {
        if (array_key_exists('env', $instances)) {
            return $instances['env'];
        }
        $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getValue($this->container);
        $concrete = is_array($bindings) ? ($bindings['env']['concrete'] ?? null) : null;
        if ($concrete === null) {
            return null;
        }
        MemberGrantException::require($concrete instanceof Closure, 'changed_policy');
        $function = new ReflectionFunction($concrete);
        $variables = $function->getStaticVariables();
        MemberGrantException::require($function->getClosureScopeClass()?->getName() === Container::class
            && $function->getClosureThis() === $this->container && array_keys($variables) === ['value']
            && is_string($variables['value']), 'changed_policy');

        return $variables['value'];
    }
}
