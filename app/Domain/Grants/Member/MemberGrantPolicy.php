<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Domain\Memberships\Production\MembershipRows;
use App\Support\Environment\TestEnvironment;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PDO;
use ReflectionFunction;
use ReflectionProperty;
use WeakMap;

/** A default-off capability baseline, never approval of facts, originals or artifact readiness. */
final class MemberGrantPolicy
{
    public const MAX_ARTIFACTS = 16;

    public const MAX_ARTIFACT_BYTES = 536870912;

    private const CAPABILITIES = [MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class, MembershipReservationAuthority::class];

    /** @var WeakMap<MembershipRows, array{pdo: PDO, driver: string, configuration: array}> */
    private WeakMap $frames;

    private Container $container;

    private Repository $repository;

    public function __construct()
    {
        $this->frames = new WeakMap;
        $this->container = Container::getInstance();
        $this->repository = $this->container->make('config');
        MemberGrantException::require(get_class($this->repository) === Repository::class, 'changed_policy');
    }

    public function __clone(): void
    {
        throw new MemberGrantException('clone_refused');
    }

    /** Configuration/capability admission only: this does not prove a native connection. */
    public function current(): array
    {
        return $this->admit(null);
    }

    /** Bind this policy's baseline to one exact live frame, never mint write authority. */
    public function currentFor(MembershipRows $rows): array
    {
        if (isset($this->frames[$rows])) {
            $configuration = $this->frames[$rows]['configuration'];
            $this->proveCurrent($configuration, $rows);

            return $configuration;
        }

        return $this->admit($rows);
    }

    public function proveConfiguration(array $expected): void
    {
        MemberGrantException::require($this->configuration() === $expected, 'changed_policy');
    }

    /** Equal scalar configurations are sufficient only for a frame this policy already issued. */
    public function proveCurrent(array $expected, MembershipRows $rows): void
    {
        $frame = $this->frames[$rows] ?? null;
        MemberGrantException::require(is_array($frame) && $frame['configuration'] === $expected, 'foreign_frame');
        $this->proveConfiguration($expected);
        $this->proveFrame($rows, $frame);
        $this->capabilitiesPresent();
        $this->proveConfiguration($expected);
    }

    private function admit(?MembershipRows $rows): array
    {
        $configuration = $this->configuration();
        MemberGrantException::require($configuration['enabled'] === true && $configuration['version'] === 1
            && $configuration['family'] === MemberGrantIntent::FAMILY && $configuration['purpose'] === MemberGrantIntent::PURPOSE, 'disabled');
        MemberGrantException::require(in_array($configuration['provenance'], [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true)
            && ($configuration['provenance'] !== IdentityPolicy::REHEARSAL || in_array($configuration['environment'], ['local', 'testing'], true))
            // Staging is a test installation: verified production provenance is refused there.
            && ($configuration['provenance'] !== IdentityPolicy::PRODUCTION || ! TestEnvironment::isStaging($configuration['environment'])), 'provenance');
        MemberGrantException::require(in_array($configuration['driver'], ['sqlite', 'mysql'], true)
            && ($configuration['provenance'] !== IdentityPolicy::PRODUCTION || $configuration['driver'] === 'mysql'), 'provenance_driver');
        foreach (['approved_definition_hash', 'approved_profile_hash', 'approved_original_terms_hash'] as $key) {
            MemberGrantException::require(is_string($configuration[$key])
                && preg_match('/\A[a-f0-9]{64}\z/D', $configuration[$key]) === 1, 'facts_absent');
        }
        $frame = $rows === null ? null : ['pdo' => $rows->identity(), 'driver' => $rows->driver(), 'configuration' => $configuration];
        if ($rows !== null) {
            $this->proveFrame($rows, $frame);
        }
        // Check the whole set before the first factory, and after each factory without resolving
        // the remaining capabilities. A factory cannot fill a previously missing prerequisite.
        $this->capabilitiesPresent();
        foreach (self::CAPABILITIES as $capability) {
            MemberGrantException::require($this->container->make($capability) instanceof $capability, 'capability_absent');
            $this->proveConfiguration($configuration);
            if ($rows !== null) {
                $this->proveFrame($rows, $frame);
            }
            $this->capabilitiesPresent();
            $this->proveConfiguration($configuration);
        }
        if ($rows !== null) {
            $this->frames[$rows] = $frame;
        }

        return $configuration;
    }

    private function capabilitiesPresent(): void
    {
        // Container getters are virtual and may execute application callbacks. The terminal
        // source proof reads only captured raw maps, and supports direct bindings/instances only.
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        $bindings = (new ReflectionProperty(Container::class, 'bindings'))->getValue($this->container);
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($this->container);
        MemberGrantException::require(is_array($instances) && is_array($bindings) && is_array($aliases), 'capability_absent');
        foreach (self::CAPABILITIES as $capability) {
            MemberGrantException::require(! array_key_exists($capability, $aliases), 'capability_absent');
            if (array_key_exists($capability, $instances)) {
                MemberGrantException::require($instances[$capability] instanceof $capability, 'capability_absent');
            } else {
                $binding = $bindings[$capability] ?? null;
                MemberGrantException::require(is_array($binding) && ($binding['concrete'] ?? null) instanceof Closure
                    && is_bool($binding['shared'] ?? null), 'capability_absent');
            }
        }
    }

    private function proveFrame(MembershipRows $rows, array $frame): void
    {
        MemberGrantException::require($rows->identity() === $frame['pdo'] && $rows->driver() === $frame['driver'], 'foreign_frame');
        try {
            $rows->assertCurrent();
        } catch (MembershipException) {
            throw new MemberGrantException('changed_primary');
        }
        MemberGrantException::require($frame['pdo']->getAttribute(PDO::ATTR_DRIVER_NAME) === $frame['driver']
            && $frame['driver'] === $frame['configuration']['driver']
            && ($frame['configuration']['provenance'] !== IdentityPolicy::PRODUCTION || $frame['driver'] === 'mysql'), 'provenance_driver');
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
        $database = $items['database'] ?? null;
        MemberGrantException::require(is_array($database) && is_string($database['default'] ?? null)
            && $database['default'] !== '' && is_array($database['connections'] ?? null), 'changed_policy');
        $connection = $database['connections'][$database['default']] ?? null;
        MemberGrantException::require(is_array($connection) && is_string($connection['driver'] ?? null), 'changed_policy');
        $result = [];
        // Copy scalar values individually: references in the repository must not mutate an issued baseline.
        foreach (['enabled', 'version', 'family', 'purpose', 'provenance', 'approved_definition_hash',
            'approved_profile_hash', 'approved_original_terms_hash'] as $key) {
            MemberGrantException::require(array_key_exists($key, $policy), 'changed_policy');
            $value = $policy[$key];
            if (! in_array($key, ['enabled', 'version', 'family', 'purpose'], true)) {
                MemberGrantException::require($value === null || is_string($value), 'changed_policy');
            }
            $result[$key] = $value;
        }
        $result['environment'] = $environment;
        $result['connection_name'] = $database['default'];
        $result['driver'] = $connection['driver'];

        return $result;
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
