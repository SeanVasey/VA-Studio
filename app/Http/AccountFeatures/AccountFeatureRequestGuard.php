<?php

namespace App\Http\AccountFeatures;

use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityMetadataAdmission;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\SymfonySessionDecorator;
use ReflectionProperty;

/** Original already-resolved request/session identities; terminal checks never resolve a new user. */
final class AccountFeatureRequestGuard
{
    private readonly object $application;

    private readonly AuthManager $manager;

    private readonly SessionGuard $guard;

    private readonly SymfonySessionDecorator $decorator;

    private readonly Store $store;

    private readonly array $scope;

    private readonly IdentityMetadataAdmission $metadata;

    private readonly \PDO $primary;

    public function __construct(private readonly Request $request, private readonly ProductionAccountFeatureIdentity $identity)
    {
        $this->application = Container::getInstance();
        $this->metadata = IdentityMetadataAdmission::capture();
        $this->primary = $this->metadata->connection()->getRawPdo();
        $instances = self::raw($this->application, Container::class, 'instances');
        $manager = is_array($instances) ? ($instances['auth'] ?? null) : null;
        if (! $manager instanceof AuthManager || $manager::class !== AuthManager::class) {
            throw new ProductionFeatureException;
        }
        $guards = self::raw($manager, AuthManager::class, 'guards');
        $guard = is_array($guards) ? ($guards['customer'] ?? null) : null;
        $decorator = self::raw($request, \Symfony\Component\HttpFoundation\Request::class, 'session');
        if ($request::class !== Request::class || ! $guard instanceof SessionGuard || $guard::class !== SessionGuard::class
            || ! $decorator instanceof SymfonySessionDecorator || $decorator::class !== SymfonySessionDecorator::class
            || $decorator->store::class !== Store::class) {
            throw new ProductionFeatureException;
        }
        $this->manager = $manager;
        $this->guard = $guard;
        $this->decorator = $decorator;
        $this->store = $decorator->store;
        $this->scope = $this->scope();
        $this->requireCurrent();
    }

    public function requireCurrent(): void
    {
        $this->metadata->requireCurrent($this->primary);
        $instances = self::raw($this->application, Container::class, 'instances');
        $guards = self::raw($this->manager, AuthManager::class, 'guards');
        if (! AccountFeatureMount::admitted() || ! is_array($instances) || ! is_array($guards)
            || ($instances['request'] ?? null) !== $this->request || ($instances['auth'] ?? null) !== $this->manager
            || ($guards['customer'] ?? null) !== $this->guard
            || self::raw($this->manager, AuthManager::class, 'app') !== $this->application
            || self::raw($this->guard, SessionGuard::class, 'session') !== $this->store
            || self::raw($this->guard, SessionGuard::class, 'request') !== $this->request
            || self::raw($this->guard, SessionGuard::class, 'user') !== $this->identity->actor()
            || self::raw($this->guard, SessionGuard::class, 'loggedOut') !== false
            || self::raw($this->request, \Symfony\Component\HttpFoundation\Request::class, 'session') !== $this->decorator
            || $this->scope() !== $this->scope) {
            throw new ProductionFeatureException;
        }
        IdentityMetadataAdmission::actorId($this->identity->actor());
    }

    private function scope(): array
    {
        $attributes = self::raw($this->store, Store::class, 'attributes');
        $id = self::raw($this->store, Store::class, 'id');
        $name = self::raw($this->store, Store::class, 'name');
        $login = 'login_customer_'.sha1(SessionGuard::class);
        $marker = is_array($attributes) ? ($attributes['_production_customer_identity'] ?? null) : null;
        if (! is_array($attributes) || ! is_string($id) || ! is_string($name)
            || ! is_array($marker) || array_keys($marker) !== ['binding_digest'] || ! is_string($marker['binding_digest'])
            || ! is_string($attributes['_token'] ?? null)
            || ! hash_equals($this->identity->principal()->sessionBindingDigest(), $marker['binding_digest'])
            || (string) ($attributes[$login] ?? '') !== (string) IdentityMetadataAdmission::actorId($this->identity->actor())) {
            throw new ProductionFeatureException;
        }

        return [$id, $name, $marker['binding_digest'], $attributes['_token'], $attributes[$login]];
    }

    private static function raw(object $object, string $class, string $property): mixed
    {
        return (new ReflectionProperty($class, $property))->getRawValue($object);
    }
}
