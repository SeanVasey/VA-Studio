<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;

/**
 * The only request-to-identity step for family 256: the T23 session marker through
 * `ProductionCustomerSessions::principal(Request)` and the trusted actor from `request->user('customer')`, captured
 * once in the request-bound capsule (`ProductionFreeGrantHttpBinding`, composed from Codex's ff0). Nothing is read from
 * request input, email or a client DTO. The same capsule is re-proved inside each command's commit (`authority()`)
 * and before any response byte (`proveCurrent()`).
 */
final readonly class ProductionFreeGrantRequestIdentity
{
    private function __construct(public ProductionCustomerPrincipal $principal, public User $actor, private ProductionFreeGrantHttpBinding $binding) {}

    public static function from(Request $request): self
    {
        $binding = ProductionFreeGrantHttpBinding::forRequest($request);

        return new self($binding->principal(), $binding->actor(), $binding);
    }

    /** Refuse unless the original request, session, marker, guard, actor and configuration are unchanged. */
    public function proveCurrent(): void
    {
        $this->binding->proveCurrent();
    }

    /** The capsule proof as the domain's optional `$authority` hook. */
    public function authority(): Closure
    {
        return $this->binding->proveCurrent(...);
    }
}
