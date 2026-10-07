<?php

namespace App\Support;

use App\Domain\Customers\CustomerPrincipal;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\SupportAttachments\AttachmentActor;
use App\Domain\SupportAttachments\AttachmentException;
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
use ReflectionProperty;
use Symfony\Component\HttpFoundation\Request as BaseRequest;

/** Cache request authority before domain callbacks; the final comparison invokes no resolver. */
final class SupportAttachmentRequestContext
{
    private array $guards = [];

    private array $guardUsers = [];

    private array $markerKeys = ['_inquiry_owner', '_customer_access', '_production_customer_identity'];

    private array $markers;

    private mixed $sessionId;

    private mixed $userResolver;

    private mixed $authResolver;

    private array $configuration;

    private function __construct(private readonly Request $request, private readonly AuthManager $auth,
        private readonly SymfonySessionDecorator $decorator, private readonly Store $store,
        private readonly Container $container, private readonly Repository $config)
    {
        // Loading guard users may invoke provider callbacks. Finish it before any domain proof.
        foreach (['web', 'customer'] as $name) {
            $guard = $auth->guard($name);
            AttachmentException::require($guard instanceof SessionGuard, 403);
            $guard->user();
            $this->guards[$name] = $guard;
            $this->guardUsers[$name] = $this->guardUser($guard);
            $this->markerKeys[] = $guard->getName();
        }
        $this->markers = $this->markers();
        $this->sessionId = $this->property(Store::class, 'id', $store);
        $this->userResolver = $this->property(Request::class, 'userResolver', $request);
        $this->authResolver = $this->property(AuthManager::class, 'userResolver', $auth);
        $this->configuration = $this->configuration();
    }

    /** Guard-provider callbacks finish before the server actor is minted. */
    public static function resolve(Request $request, \Closure $mint): array
    {
        $decorator = (new ReflectionProperty(BaseRequest::class, 'session'))->getValue($request);
        $auth = Auth::getFacadeRoot();
        $container = app();
        $config = config();
        AttachmentException::require($decorator instanceof SymfonySessionDecorator
            && $decorator->store instanceof Store && $auth instanceof AuthManager
            && $container instanceof Container && $config instanceof Repository, 403);
        $context = new self($request, $auth, $decorator, $decorator->store, $container, $config);
        $context->prove();
        $actor = $mint();
        AttachmentException::require($actor instanceof AttachmentActor, 403);
        $context->bindActor($actor);
        $context->prove();

        return [$actor, $context];
    }

    private function bindActor(AttachmentActor $actor): void
    {
        $markers = $this->markers();
        if ($actor->audience === 'visitor') {
            $owner = $markers['_inquiry_owner'] ?? null;
            $context = $actor->user === null ? 'guest' : 'user:'.$this->userId($actor->user);
            AttachmentException::require(is_array($owner) && array_keys($owner) === ['context', 'secret']
                && $owner['context'] === $context && is_string($owner['secret'])
                && preg_match('/\A[a-f0-9]{64}\z/D', $owner['secret']) === 1
                && is_string($this->configuration['key']) && $this->configuration['key'] !== ''
                && hash_equals((string) $actor->ownerHash(), hash_hmac('sha256', "vasey-inquiry-owner-v1\0".$context."\0".$owner['secret'], $this->configuration['key'])), 403);
            // A first inquiry owner can be created by the server actor mint. Existing owners cannot be replaced.
            if (! array_key_exists('_inquiry_owner', $this->markers)) {
                $this->markers['_inquiry_owner'] = $owner;
            }
        } elseif ($actor->audience === 'customer') {
            $principal = $actor->principal;
            AttachmentException::require($actor->user !== null && ($principal instanceof CustomerPrincipal || $principal instanceof ProductionCustomerPrincipal)
                && $this->userId($actor->user) === $principal->userId, 403);
            if ($principal instanceof CustomerPrincipal) {
                AttachmentException::require(($markers['_customer_access'] ?? null) === ['account_id' => $principal->accountId,
                    'access_version' => $principal->accessVersion, 'credential_stamp' => $principal->credentialStamp], 403);
            } else {
                AttachmentException::require(($markers['_production_customer_identity'] ?? null) === ['binding_digest' => $principal->sessionBindingDigest()], 403);
            }
        }
    }

    private function userId(Model $user): int
    {
        $id = $this->property(Model::class, 'attributes', $user)['id'] ?? null;
        AttachmentException::require((is_int($id) && $id > 0) || (is_string($id) && preg_match('/\A[1-9][0-9]*\z/D', $id) === 1 && (string) (int) $id === $id), 403);

        return (int) $id;
    }

    private function configuration(): array
    {
        $items = $this->property(Repository::class, 'items', $this->config);

        return ['key' => $items['app']['key'] ?? null, 'guard' => $items['auth']['defaults']['guard'] ?? null];
    }

    public function prove(): void
    {
        AttachmentException::require($this->property(BaseRequest::class, 'session', $this->request) === $this->decorator
            && $this->decorator->store === $this->store
            && $this->property(Store::class, 'id', $this->store) === $this->sessionId
            && $this->property(Request::class, 'userResolver', $this->request) === $this->userResolver
            && $this->markers() === $this->markers, 403);
        $resolved = (new ReflectionProperty(Facade::class, 'resolvedInstance'))->getValue();
        $instances = $this->property(Container::class, 'instances', $this->container);
        AttachmentException::require(($resolved['auth'] ?? null) === $this->auth && ($instances['auth'] ?? null) === $this->auth
            && ($instances['config'] ?? null) === $this->config
            && $this->property(AuthManager::class, 'userResolver', $this->auth) === $this->authResolver
            && $this->configuration() === $this->configuration, 403);
        $current = $this->property(AuthManager::class, 'guards', $this->auth);
        foreach ($this->guards as $name => $guard) {
            AttachmentException::require(($current[$name] ?? null) === $guard
                && $this->guardUser($guard) === $this->guardUsers[$name], 403);
        }
    }

    private function markers(): array
    {
        $attributes = $this->property(Store::class, 'attributes', $this->store);

        return array_intersect_key($attributes, array_flip($this->markerKeys));
    }

    private function guardUser(SessionGuard $guard): array
    {
        $user = $this->property(SessionGuard::class, 'user', $guard);

        return [$user, $user instanceof Model ? $this->property(Model::class, 'attributes', $user) : null];
    }

    private function property(string $class, string $name, object $object): mixed
    {
        return (new ReflectionProperty($class, $name))->getValue($object);
    }

    public function __serialize(): array
    {
        throw new \LogicException('Private request authority cannot be serialized.');
    }

    public function __debugInfo(): array
    {
        return ['captured' => true];
    }
}
