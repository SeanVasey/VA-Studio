<?php

namespace App\Http\ProductionCheckout;

use App\Domain\Grants\PaidTax\PaidTaxConfiguration;
use Illuminate\Config\Repository;
use ReflectionProperty;
use Throwable;

/**
 * DD-14: v1 sells through exactly one production checkout chain, the tax-calculating one (ProductionTaxCheckout). The
 * tax-refusing chain (ProductionCheckout) must never be admitted beside it. Both chains keep their own literal-false mount
 * constant; this guard is the shared fail-closed rule both mounts consult: when both mount flags are literally `true` in
 * loaded configuration, neither chain is admitted (no provider, no routes, private 404 on stale routes, reconcile command
 * refused). It deliberately ignores the environment, so the conflict is refused wherever either flag would otherwise admit.
 * Unreadable configuration is a conflict too. Reads plain loaded storage only, never a resolver, like the mounts themselves.
 * The tax mount is read through PaidTaxConfiguration::read, exactly as TaxHttpMount reads it (nested or flat dotted key), so the
 * guard and the mount can never disagree about whether that flag is true. Scope: this is the mount/composition layer only; the
 * policy and runtime flags of the two chains are not made exclusive here.
 */
final class ProductionChainExclusivity
{
    public const REFUSAL = 'Production checkout: production_checkout.mounted and production-tax-checkout.http_mounted are both true; DD-14 admits only the tax-calculating chain, so neither is admitted.';

    public static function conflict(Repository $repository): bool
    {
        try {
            $items = (new ReflectionProperty(Repository::class, 'items'))->getRawValue($repository);

            return ! is_array($items) || (($items['production_checkout']['mounted'] ?? null) === true
                && PaidTaxConfiguration::read($repository, 'production-tax-checkout.http_mounted') === true);
        } catch (Throwable) {
            return true;
        }
    }
}
