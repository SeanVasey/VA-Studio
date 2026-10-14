<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityPolicy;
use App\Domain\Memberships\Production\MemberGrantAuthority;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipEligibleLicenseAuthority;
use App\Domain\Memberships\Production\MembershipPaidInvoiceAuthority;
use App\Domain\Memberships\Production\MembershipPolicy;
use App\Domain\Memberships\Production\MembershipPolicyFactsAuthority;
use App\Domain\Memberships\Production\MembershipReservationAuthority;
use App\Support\Environment\TestEnvironment;
use Closure;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use ReflectionFunction;
use ReflectionProperty;

/** Explicit historical-read gate. Approved live hashes and renderer availability confer no retained-read authority. */
final class MemberActivationReadConfiguration
{
    private Container $container;

    private Repository $repository;

    private DatabaseManager $manager;

    private array $baseline;

    private const CAPABILITIES = [MemberGrantFactsAuthority::class, MemberOriginalArtifactAuthority::class,
        MembershipReservationAuthority::class, MembershipPaidInvoiceAuthority::class, MembershipPolicyFactsAuthority::class,
        MembershipEligibleLicenseAuthority::class, MemberGrantAuthority::class, MemberLicenseAuthorizations::class];

    public function __construct(MemberGrantActivations $owner)
    {
        $this->container = Container::getInstance();
        // Exact route identity is necessary. The separate FinishBoundary additionally excludes
        // executable container callbacks at the shared committed frame's final getter path.
        MemberGrantException::require(get_class($this->container) === Application::class, 'unsupported_container');
        $instances = self::raw(Container::class, 'instances', $this->container);
        MemberGrantException::require(is_array($instances) && ($instances['config'] ?? null) instanceof Repository
            && get_class($instances['config']) === Repository::class && ($instances['db'] ?? null) instanceof DatabaseManager
            && get_class($instances['db']) === DatabaseManager::class, 'changed_configuration');
        $this->repository = $instances['config'];
        $this->manager = $instances['db'];
        $this->baseline = $this->snapshot();
        foreach (self::CAPABILITIES as $capability) {
            $instance = $this->baseline['instances'][$capability];
            $binding = $this->baseline['bindings'][$capability];
            MemberGrantException::require($instance !== null ? $instance instanceof $capability
                : (is_array($binding) && ($binding['concrete'] ?? null) instanceof Closure
                    && is_bool($binding['shared'] ?? null)), 'capability_absent');
        }
        $this->gate();
        $expected = [MemberOriginalArtifactAuthority::class => $owner->artifactAuthority(),
            MemberGrantAuthority::class => $owner->grantAuthority(), MemberLicenseAuthorizations::class => $owner->authorizations()];
        foreach (self::CAPABILITIES as $capability) {
            $actual = $this->container->make($capability);
            MemberGrantException::require($actual instanceof $capability
                && (! isset($expected[$capability]) || $actual === $expected[$capability]), 'foreign_assembly');
            $this->admit();
        }
        $this->admit();
    }

    public function admit(): void
    {
        MemberGrantException::require($this->snapshot() === $this->baseline, 'changed_configuration');
        $this->gate();
    }

    private function gate(): void
    {
        $configuration = $this->baseline['configuration'];
        $member = $configuration['member-grants'] ?? [];
        $membership = $configuration['production-memberships'] ?? [];
        MemberGrantException::require(($member['enabled'] ?? null) === true && ($member['version'] ?? null) === 1
            && ($member['family'] ?? null) === MemberGrantIntent::FAMILY && ($member['purpose'] ?? null) === MemberGrantIntent::PURPOSE
            && ($membership['enabled'] ?? null) === true && ($membership['version'] ?? null) === MembershipPolicy::VERSION, 'disabled');
        $provenance = $member['provenance'] ?? null;
        $environment = $this->baseline['environment'];
        $default = $configuration['database']['default'] ?? null;
        $driver = is_string($default) ? ($configuration['database']['connections'][$default]['driver'] ?? null) : null;
        MemberGrantException::require(is_string($environment) && $environment !== ''
            && in_array($provenance, [IdentityPolicy::REHEARSAL, IdentityPolicy::PRODUCTION], true)
            && ($membership['provenance'] ?? null) === $provenance
            && ($provenance !== IdentityPolicy::REHEARSAL || in_array($environment, ['local', 'testing'], true))
            && ($provenance !== IdentityPolicy::PRODUCTION || ! TestEnvironment::isStaging($environment))
            && in_array($driver, ['sqlite', 'mysql'], true)
            && ($provenance !== IdentityPolicy::PRODUCTION || $driver === 'mysql'), 'provenance');
    }

    public function provenance(): string
    {
        return $this->baseline['configuration']['member-grants']['provenance'];
    }

    private function snapshot(): array
    {
        $instances = self::raw(Container::class, 'instances', $this->container);
        $facades = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        MemberGrantException::require(Container::getInstance() === $this->container && is_array($instances)
            && ($instances['config'] ?? null) === $this->repository && ($instances['db'] ?? null) === $this->manager
            && (new ReflectionProperty(Facade::class, 'app'))->getValue() === $this->container
            && is_array($facades) && ($facades['db'] ?? null) === $this->manager
            && self::raw(DatabaseManager::class, 'app', $this->manager) === $this->container, 'changed_configuration');
        $remaining = 30000;
        $items = self::raw(Repository::class, 'items', $this->repository);
        $connections = self::raw(DatabaseManager::class, 'connections', $this->manager);
        MemberGrantException::require(is_array($items) && is_array($connections) && $connections !== [], 'changed_configuration');
        $result = ['configuration' => self::copy($items, $remaining), 'environment' => $this->environment($instances), 'connections' => []];
        foreach ($connections as $name => $connection) {
            MemberGrantException::require(is_object($connection)
                && in_array(get_class($connection), [MySqlConnection::class, SQLiteConnection::class], true), 'changed_configuration');
            $result['connections'][$name] = [$connection, self::raw(Connection::class, 'pdo', $connection),
                self::raw(Connection::class, 'readPdo', $connection),
                self::raw(Connection::class, 'directPdo', $connection),
                self::copy(self::raw(Connection::class, 'config', $connection), $remaining),
                self::copy(self::raw(Connection::class, 'readPdoConfig', $connection), $remaining),
                self::copy(self::raw(Connection::class, 'directPdoConfig', $connection), $remaining)];
        }
        foreach (['instances', 'bindings', 'aliases', 'extenders', 'beforeResolvingCallbacks', 'resolvingCallbacks', 'afterResolvingCallbacks'] as $property) {
            $map = self::raw(Container::class, $property, $this->container);
            MemberGrantException::require(is_array($map), 'changed_configuration');
            foreach (self::CAPABILITIES as $capability) {
                MemberGrantException::require($property !== 'aliases' || ! isset($map[$capability]), 'foreign_assembly');
                $result[$property][$capability] = self::copy($map[$capability] ?? null, $remaining, objects: true);
            }
        }

        return $result;
    }

    /** Same callback-free Laravel scalar-binding admission used by MemberGrantPolicy. */
    private function environment(array $instances): mixed
    {
        if (array_key_exists('env', $instances)) {
            return $instances['env'];
        }
        $bindings = self::raw(Container::class, 'bindings', $this->container);
        $concrete = is_array($bindings) ? ($bindings['env']['concrete'] ?? null) : null;
        if ($concrete === null) {
            return null;
        }
        MemberGrantException::require($concrete instanceof Closure, 'changed_configuration');
        $function = new ReflectionFunction($concrete);
        $variables = $function->getStaticVariables();
        MemberGrantException::require($function->getClosureScopeClass()?->getName() === Container::class
            && $function->getClosureThis() === $this->container && array_keys($variables) === ['value']
            && is_string($variables['value']), 'changed_configuration');

        return $variables['value'];
    }

    private static function raw(string $class, string $property, object $object): mixed
    {
        return (new ReflectionProperty($class, $property))->getValue($object);
    }

    private static function copy(mixed $value, int &$remaining, int $depth = 0, bool $objects = false): mixed
    {
        MemberGrantException::require(--$remaining >= 0 && $depth <= 32, 'changed_configuration');
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $child) {
                $result[$key] = self::copy($child, $remaining, $depth + 1, $objects);
            }

            return $result;
        }
        MemberGrantException::require($value === null || is_scalar($value) || ($objects && is_object($value)), 'changed_configuration');

        return $value;
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_configuration');
    }

    public function __debugInfo(): array
    {
        return ['member_activation_read_configuration' => true];
    }
}
