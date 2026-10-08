<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

use LogicException;

/** Default adapter: no provider, credential or transport exists. Every provider call refuses. */
final class UnboundProductionSuppressionProvider implements ProductionSuppressionProvider
{
    public function boundTo(): ?string
    {
        return null;
    }

    public function suppress(ProductionSuppressionRequest $request): void
    {
        throw new LogicException('No production suppression provider is bound.');
    }

    public function inspect(ProductionSuppressionRequest $request): ?ProductionSuppressionReceipt
    {
        throw new LogicException('No production suppression provider is bound.');
    }
}
