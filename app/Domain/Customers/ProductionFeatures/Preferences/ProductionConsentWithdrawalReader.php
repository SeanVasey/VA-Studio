<?php

namespace App\Domain\Customers\ProductionFeatures\Preferences;

use App\Domain\Customers\ProductionFeatures\ProductionFeatureContext;

/** No standalone authority, address input, initialization or provider side effect. */
final class ProductionConsentWithdrawalReader
{
    public function read(ProductionFeatureContext $context, int $expectedConsentVersion): ?ProductionConsentWithdrawal
    {
        return ProductionConsentWithdrawal::capture($context, $expectedConsentVersion);
    }
}
