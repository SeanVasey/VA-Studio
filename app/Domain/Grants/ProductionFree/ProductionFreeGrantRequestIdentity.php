<?php

namespace App\Domain\Grants\ProductionFree;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * The only request-to-identity step for family 256: the T23 session marker through
 * `ProductionCustomerSessions::principal(Request)` and the trusted actor from `request->user('customer')`.
 * Nothing is read from request input, email or a client DTO. Root's future controllers call this; no route exists.
 */
final readonly class ProductionFreeGrantRequestIdentity
{
    private function __construct(public ProductionCustomerPrincipal $principal, public User $actor) {}

    public static function from(Request $request): self
    {
        try {
            $principal = (new ProductionCustomerSessions)->principal($request);
        } catch (IdentityException) {
            throw new ProductionFreeGrantException('identity_refused');
        }
        $actor = $request->user('customer');
        ProductionFreeGrantException::require($actor instanceof User && (int) $actor->getKey() === $principal->userId, 'identity_refused');

        return new self($principal, $actor);
    }
}
