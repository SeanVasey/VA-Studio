<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Store;
use Illuminate\Session\SymfonySessionDecorator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;
use LogicException;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request as BaseRequest;

/**
 * The request-bound identity capsule for family 256, composed from Codex's reviewed ff0 capsule (`ff0f7a85` on
 * `codex/production-free-http-parent-guard-20261007`, file `app/Domain/Grants/Free/Production/ProductionFreeGrantHttpBinding.php`)
 * onto the canonical 256 seams:
 * `ProductionFreeGrantPolicy` instead of ff0's separate identity configuration, and `ProductionFreeGrantException`
 * (`identity_refused`) instead of the old family's status exception.
 *
 * It captures the original HTTP authority once (request, session decorator and store, session id, the T23 marker and
 * the customer login key, the customer guard and its resolved actor with its attribute state, both user resolvers,
 * the auth manager and the plain configuration parents) and `proveCurrent()` re-reads only that cached state through
 * reflection. It never resolves a provider, a guard or a user again, so a later callback cannot satisfy it with a
 * replacement. Callers keep the same capsule through their terminal proof: before the commit, before JSON is
 * released and before the first byte of a download.
 */
final class ProductionFreeGrantHttpBinding implements JsonSerializable
{
    /** Configuration parents whose plain values are part of the original authority. */
    private const PARENTS = ['app', 'auth', 'production-customer-identity', 'production-free-grants'];

    private ProductionCustomerPrincipal $principal;

    private array $markers;

    private array $markerKeys;

    private array $actorState;

    private array $configuration;

    private mixed $sessionId;

    private mixed $userResolver;

    private mixed $authResolver;

    private function __construct(private readonly Request $request, private readonly AuthManager $auth,
        private readonly SessionGuard $guard, private readonly User $actor,
        private readonly SymfonySessionDecorator $decorator, private readonly Store $store,
        private readonly Container $container, private readonly Repository $config)
    {
        $this->markerKeys = ['_production_customer_identity', $guard->getName()];
        $this->markers = $this->markers();
        $this->actorState = $this->actorState();
        $this->sessionId = self::property(Store::class, 'id', $store);
        $this->userResolver = self::property(Request::class, 'userResolver', $request);
        $this->authResolver = self::property(AuthManager::class, 'userResolver', $auth);
        $this->configuration = $this->configuration();
    }

    /** Only an actual request can capture authority; callers cannot submit a principal, marker or proof. */
    public static function forRequest(Request $request): self
    {
        $container = Container::getInstance();
        $config = $container->make('config');
        ProductionFreeGrantException::require($container::class === Application::class
            && $config instanceof Repository && $config::class === Repository::class, 'identity_refused');
        // Admit raw parents before policy or guard configuration may traverse them.
        self::configurationFrom($container, $config);
        $policy = (new ProductionFreeGrantPolicy)->current();
        $auth = Auth::getFacadeRoot();
        $decorator = self::property(BaseRequest::class, 'session', $request);
        ProductionFreeGrantException::require($auth instanceof AuthManager && $auth::class === AuthManager::class
            && $decorator instanceof SymfonySessionDecorator && $decorator::class === SymfonySessionDecorator::class
            && in_array($decorator->store::class, [Store::class, EncryptedStore::class], true), 'identity_refused');
        // These may invoke application callbacks. Complete them before capturing the original source.
        $guard = $auth->guard('customer');
        ProductionFreeGrantException::require($guard instanceof SessionGuard && $guard::class === SessionGuard::class, 'identity_refused');
        $actor = $guard->user();
        $requestActor = $request->user('customer');
        ProductionFreeGrantException::require($actor instanceof User && $actor::class === User::class && $requestActor === $actor, 'identity_refused');
        $binding = new self($request, $auth, $guard, $actor, $decorator, $decorator->store, $container, $config);
        $binding->proveCurrent();
        ProductionFreeGrantException::require((new ProductionFreeGrantPolicy)->current() === $policy, 'identity_refused');
        $binding->proveCurrent();
        try {
            $principal = (new ProductionCustomerSessions)->principal($request);
            // Digest computation can read configuration; finish it before the fixed terminal comparison.
            $marker = ['binding_digest' => $principal->sessionBindingDigest()];
        } catch (IdentityException) {
            throw new ProductionFreeGrantException('identity_refused');
        }
        $id = $binding->actorState['attributes']['id'] ?? null;
        ProductionFreeGrantException::require(((is_int($id) && $id > 0) || (is_string($id)
            && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1 && (string) (int) $id === $id))
            && (int) $id === $principal->userId && $principal->provenance === $policy['provenance']
            && ($binding->markers['_production_customer_identity'] ?? null) === $marker, 'identity_refused');
        $binding->principal = $principal;
        $binding->proveCurrent();

        return $binding;
    }

    public function principal(): ProductionCustomerPrincipal
    {
        return $this->principal;
    }

    public function actor(): User
    {
        return $this->actor;
    }

    /** Call after extensible work and before the consumer's final fixed raw identity/source proof. Reflection reads only. */
    public function proveCurrent(): void
    {
        ProductionFreeGrantException::require(Container::getInstance() === $this->container
            && (new ReflectionProperty(Facade::class, 'app'))->getValue() === $this->container
            && self::property(BaseRequest::class, 'session', $this->request) === $this->decorator
            && $this->decorator->store === $this->store
            && self::property(Store::class, 'id', $this->store) === $this->sessionId
            && self::property(Request::class, 'userResolver', $this->request) === $this->userResolver
            && $this->markers() === $this->markers, 'identity_refused');
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $instances = self::property(Container::class, 'instances', $this->container);
        $guards = self::property(AuthManager::class, 'guards', $this->auth);
        // Actual arrays first: an ArrayAccess replacement must never run its offset callback here.
        ProductionFreeGrantException::require(is_array($resolved) && is_array($instances) && is_array($guards)
            && ($resolved['auth'] ?? null) === $this->auth
            && ($instances['auth'] ?? null) === $this->auth && ($instances['config'] ?? null) === $this->config
            && ($guards['customer'] ?? null) === $this->guard
            && self::property(AuthManager::class, 'userResolver', $this->auth) === $this->authResolver
            && self::property(SessionGuard::class, 'user', $this->guard) === $this->actor
            && self::property(SessionGuard::class, 'loggedOut', $this->guard) === false
            && $this->actorState() === $this->actorState
            && $this->configuration() === $this->configuration, 'identity_refused');
    }

    private function markers(): array
    {
        $attributes = self::property(Store::class, 'attributes', $this->store);
        ProductionFreeGrantException::require(is_array($attributes), 'identity_refused');

        return array_intersect_key($attributes, array_flip($this->markerKeys));
    }

    private function actorState(): array
    {
        return ['attributes' => self::property(Model::class, 'attributes', $this->actor),
            'original' => self::property(Model::class, 'original', $this->actor),
            'exists' => self::property(Model::class, 'exists', $this->actor)];
    }

    private function configuration(): array
    {
        return self::configurationFrom($this->container, $this->config);
    }

    /** Plain raw parents and typed leaves (ff0): an ArrayAccess parent or a Stringable leaf is refused before any offset or conversion. */
    private static function configurationFrom(Container $container, Repository $config): array
    {
        $items = self::property(Repository::class, 'items', $config);
        $instances = self::property(Container::class, 'instances', $container);
        ProductionFreeGrantException::require(is_array($items) && is_array($instances), 'identity_refused');
        foreach (self::PARENTS as $parent) {
            ProductionFreeGrantException::require(is_array($items[$parent] ?? []), 'identity_refused');
        }
        // A cached Laravel environment may legitimately be null (ff0f7a85); anything present must be a non-empty string.
        $environment = $instances['env'] ?? null;
        ProductionFreeGrantException::require(is_string($items['app']['key'] ?? null) && $items['app']['key'] !== ''
            && ($environment === null || (is_string($environment) && $environment !== '')), 'identity_refused');

        return ['key' => $items['app']['key'], 'environment' => $environment, 'auth' => $items['auth'] ?? null,
            'identity' => $items['production-customer-identity'] ?? null, 'free' => $items['production-free-grants'] ?? null];
    }

    private static function property(string $class, string $name, object $object): mixed
    {
        return (new ReflectionProperty($class, $name))->getRawValue($object);
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new LogicException('Free HTTP authority cannot be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Free HTTP authority is not a public projection.');
    }

    public function __debugInfo(): array
    {
        return ['captured' => true];
    }
}
