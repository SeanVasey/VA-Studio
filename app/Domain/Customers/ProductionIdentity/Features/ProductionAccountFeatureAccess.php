<?php

namespace App\Domain\Customers\ProductionIdentity\Features;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionCustomerAccess;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Http\Request;

/** Operative current/historical seam. Consumers must retain this binding with newly authored data. */
final class ProductionAccountFeatureAccess
{
    public function forRequest(Request $request, string $serverFeature): ProductionAccountFeatureIdentity
    {
        return ProductionAccountFeatureIdentity::forRequest($request, $serverFeature);
    }

    /** Call before module/catalog locks, using that module's captured primary reader. */
    public function lock(ProductionAccountFeatureIdentity $identity, CurrentRows $reader): array
    {
        $policy = $this->policy($identity);
        $configuration = $policy->current();
        $raw = (new ProductionCustomerAccess)->lock($identity->principal(), $identity->actor(), $reader);
        if ($policy->current() !== $configuration || $identity->principal()->provenance !== $configuration['provenance']) {
            throw new IdentityException;
        }

        return ['configuration' => $configuration, 'identity' => $raw];
    }

    /** Pure direct terminal proof after all module/decrypt/render/audit hooks. */
    public function proveCurrent(ProductionAccountFeatureIdentity $identity, CurrentRows $reader, array $expectedRaw): void
    {
        $policy = $this->policy($identity);
        if (array_keys($expectedRaw) !== ['configuration', 'identity'] || ! is_array($expectedRaw['identity'])
            || $policy->current() !== $expectedRaw['configuration']) {
            throw new IdentityException;
        }
        (new ProductionCustomerAccess)->proveCurrent($identity->principal(), $identity->actor(), $reader, $expectedRaw['identity']);
    }

    /** Nonsecret creation identity. It does not itself authorize a write; lock/proveCurrent must surround it. */
    public function durableBinding(ProductionAccountFeatureIdentity $identity): array
    {
        $policy = $this->policy($identity);

        return ['feature_schema_version' => 1, 'feature' => $identity->feature, 'feature_policy_version' => $policy->version,
            'buyer_binding' => (new ProductionCustomerAccess)->durableBinding($identity->principal())];
    }

    /** Never adopt an old unbound row, another owner, another feature or another provenance. */
    public function verifyOriginalBinding(ProductionAccountFeatureIdentity $identity, array $binding, CurrentRows $reader): array
    {
        $buyer = $this->original($identity, $binding);

        return (new ProductionCustomerAccess)->verifyHistoricalBinding($buyer, $reader);
    }

    public function proveOriginalBindingCurrent(ProductionAccountFeatureIdentity $identity, array $binding, CurrentRows $reader, array $expectedRaw): void
    {
        $buyer = $this->original($identity, $binding);
        (new ProductionCustomerAccess)->proveHistoricalBindingCurrent($buyer, $reader, $expectedRaw);
    }

    private function original(ProductionAccountFeatureIdentity $identity, array $binding): array
    {
        $policy = $this->policy($identity);
        $policy->current();
        $keys = array_keys($binding);
        sort($keys);
        if ($keys !== ['buyer_binding', 'feature', 'feature_policy_version', 'feature_schema_version']
            || $binding['feature_schema_version'] !== 1 || $binding['feature'] !== $identity->feature
            || $binding['feature_policy_version'] !== $policy->version || ! is_array($binding['buyer_binding'])) {
            throw new IdentityException;
        }
        $current = $identity->principal()->durableBinding();
        foreach (['origin_id', 'provenance', 'account_id', 'account_public_id', 'user_id'] as $key) {
            if (($binding['buyer_binding'][$key] ?? null) !== $current[$key]) {
                throw new IdentityException;
            }
        }

        return $binding['buyer_binding'];
    }

    private function policy(ProductionAccountFeatureIdentity $identity): ProductionAccountFeaturePolicy
    {
        $policy = new ProductionAccountFeaturePolicy($identity->feature);
        if ($identity->featureVersion !== $policy->version) {
            throw new IdentityException;
        }

        return $policy;
    }
}
