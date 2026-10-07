<?php

namespace App\Support;

use App\Domain\SupportAttachments\AttachmentException;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Session\SymfonySessionDecorator;
use Illuminate\Support\Facades\Auth;
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

    private function __construct(private readonly Request $request, private readonly AuthManager $auth,
        private readonly SymfonySessionDecorator $decorator, private readonly Store $store)
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
    }

    public static function capture(Request $request): self
    {
        $decorator = (new ReflectionProperty(BaseRequest::class, 'session'))->getValue($request);
        $auth = Auth::getFacadeRoot();
        AttachmentException::require($decorator instanceof SymfonySessionDecorator
            && $decorator->store instanceof Store && $auth instanceof AuthManager, 403);

        return new self($request, $auth, $decorator, $decorator->store);
    }

    public function prove(): void
    {
        AttachmentException::require($this->property(BaseRequest::class, 'session', $this->request) === $this->decorator
            && $this->decorator->store === $this->store
            && $this->property(Store::class, 'id', $this->store) === $this->sessionId
            && $this->property(Request::class, 'userResolver', $this->request) === $this->userResolver
            && $this->markers() === $this->markers, 403);
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
