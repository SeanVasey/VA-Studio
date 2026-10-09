<?php

namespace App\Domain\Customers\ProductionIdentity\Features;

use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\ProductionCustomerSessions;
use App\Models\User;
use Illuminate\Http\Request;
use JsonSerializable;
use LogicException;

/** Server-only current session authority for a specific new consumer, not a legacy principal conversion. */
final readonly class ProductionAccountFeatureIdentity implements JsonSerializable
{
    private function __construct(public string $feature, public string $featureVersion,
        private ProductionCustomerPrincipal $principal, private User $actor) {}

    public static function forRequest(Request $request, string $serverFeature): self
    {
        $policy = new ProductionAccountFeaturePolicy($serverFeature);
        $configuration = $policy->current();
        $principal = (new ProductionCustomerSessions)->principal($request);
        $actor = $request->user('customer');
        if (! $actor instanceof User || get_class($actor) !== User::class || $principal->provenance !== $configuration['provenance']) {
            throw new IdentityException;
        }
        (new ProductionCustomerAccess)->current($principal, $actor);
        if ($policy->current() !== $configuration) {
            throw new IdentityException;
        }

        return new self($serverFeature, $policy->version, $principal, $actor);
    }

    public function principal(): ProductionCustomerPrincipal
    {
        return $this->principal;
    }

    public function actor(): User
    {
        return $this->actor;
    }

    public function __debugInfo(): array
    {
        return ['feature' => $this->feature, 'version' => $this->featureVersion, 'provenance' => $this->principal->provenance];
    }

    public function __serialize(): never
    {
        throw new LogicException('Feature identity must not be serialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Feature identity is not an HTTP projection.');
    }
}
