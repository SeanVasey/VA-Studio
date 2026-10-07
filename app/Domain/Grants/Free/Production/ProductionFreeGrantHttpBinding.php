<?php

namespace App\Domain\Grants\Free\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Models\User;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\SymfonySessionDecorator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use JsonSerializable;
use LogicException;
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request as BaseRequest;

/** Original HTTP authority. Final proof reads cached state without resolving providers or users again. */
final class ProductionFreeGrantHttpBinding implements JsonSerializable
{
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
        $this->sessionId = $this->property(Store::class, 'id', $store);
        $this->userResolver = $this->property(Request::class, 'userResolver', $request);
        $this->authResolver = $this->property(AuthManager::class, 'userResolver', $auth);
        $this->configuration = $this->configuration();
    }

    /** Only an actual request can capture authority; callers cannot submit a principal, marker or proof. */
    public static function forRequest(Request $request): self
    {
        $container = app();
        $config = config();
        FreeGrantException::require($container instanceof Container && $config instanceof Repository, 403);
        // Admit raw parents before policy/guard configuration may traverse them.
        self::configurationFrom($container, $config);
        $policy = (new ProductionFreeGrantIdentityPolicy)->current();
        $auth = Auth::getFacadeRoot();
        $decorator = (new ReflectionProperty(BaseRequest::class, 'session'))->getValue($request);
        FreeGrantException::require($auth instanceof AuthManager && $container instanceof Container
            && $config instanceof Repository && $decorator instanceof SymfonySessionDecorator
            && $decorator->store instanceof Store, 403);
        // These may invoke application callbacks. Complete them before capturing the original source.
        $guard = $auth->guard('customer');
        FreeGrantException::require($guard instanceof SessionGuard, 403);
        $actor = $guard->user();
        $requestActor = $request->user('customer');
        FreeGrantException::require($actor instanceof User && $requestActor === $actor, 403);
        $binding = new self($request, $auth, $guard, $actor, $decorator, $decorator->store, $container, $config);
        $binding->proveCurrent();
        FreeGrantException::require((new ProductionFreeGrantIdentityPolicy)->current() === $policy, 403);
        $binding->proveCurrent();
        try {
            $principal = (new ProductionCustomerSessions)->principal($request);
            // Digest computation can read configuration; finish it before the fixed terminal comparison.
            $marker = ['binding_digest' => $principal->sessionBindingDigest()];
        } catch (IdentityException) {
            throw new FreeGrantException(403);
        }
        $id = $binding->actorState['attributes']['id'] ?? null;
        FreeGrantException::require(((is_int($id) && $id > 0) || (is_string($id)
            && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1 && (string) (int) $id === $id))
            && (int) $id === $principal->userId && $principal->provenance === $policy['provenance']
            && ($binding->markers['_production_customer_identity'] ?? null) === $marker, 403);
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

    /** Call after extensible work, before the consumer's final fixed raw identity/source proof. */
    public function proveCurrent(): void
    {
        FreeGrantException::require(Container::getInstance() === $this->container
            && (new ReflectionProperty(Facade::class, 'app'))->getValue() === $this->container
            && $this->property(BaseRequest::class, 'session', $this->request) === $this->decorator
            && $this->decorator->store === $this->store
            && $this->property(Store::class, 'id', $this->store) === $this->sessionId
            && $this->property(Request::class, 'userResolver', $this->request) === $this->userResolver
            && $this->markers() === $this->markers, 403);
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $instances = $this->property(Container::class, 'instances', $this->container);
        $guards = $this->property(AuthManager::class, 'guards', $this->auth);
        FreeGrantException::require(($resolved['auth'] ?? null) === $this->auth
            && ($instances['auth'] ?? null) === $this->auth && ($instances['config'] ?? null) === $this->config
            && ($guards['customer'] ?? null) === $this->guard
            && $this->property(AuthManager::class, 'userResolver', $this->auth) === $this->authResolver
            && $this->property(SessionGuard::class, 'user', $this->guard) === $this->actor
            && $this->actorState() === $this->actorState
            && $this->configuration() === $this->configuration, 403);
    }

    private function markers(): array
    {
        $attributes = $this->property(Store::class, 'attributes', $this->store);

        return array_intersect_key($attributes, array_flip($this->markerKeys));
    }

    private function actorState(): array
    {
        return ['attributes' => $this->property(Model::class, 'attributes', $this->actor),
            'original' => $this->property(Model::class, 'original', $this->actor), 'exists' => $this->actor->exists];
    }

    private function configuration(): array
    {
        return self::configurationFrom($this->container, $this->config);
    }

    private static function configurationFrom(Container $container, Repository $config): array
    {
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($config);
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($container);
        FreeGrantException::require(is_array($items) && is_array($instances), 403);
        foreach (['app', 'auth', 'production-customer-identity', 'production-free-grant-identity', 'free-grants'] as $parent) {
            // ArrayAccess parents can execute after the preceding fixed marker/actor proof.
            FreeGrantException::require(is_array($items[$parent] ?? []), 403);
        }
        $environment = $instances['env'] ?? null;
        FreeGrantException::require(is_string($items['app']['key'] ?? null) && $items['app']['key'] !== ''
            && ($environment === null || (is_string($environment) && $environment !== '')), 403);

        return ['key' => $items['app']['key'] ?? null, 'environment' => $instances['env'] ?? null,
            'auth' => $items['auth'] ?? null, 'identity' => $items['production-customer-identity'] ?? null,
            'free_identity' => $items['production-free-grant-identity'] ?? null, 'free' => $items['free-grants'] ?? null];
    }

    private function property(string $class, string $name, object $object): mixed
    {
        return (new ReflectionProperty($class, $name))->getValue($object);
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
