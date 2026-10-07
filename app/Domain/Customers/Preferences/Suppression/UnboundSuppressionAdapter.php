<?php

namespace App\Domain\Customers\Preferences\Suppression;

use LogicException;

final class UnboundSuppressionAdapter implements SuppressionAdapter
{
    public function boundTo(): ?string
    {
        return null;
    }

    public function suppress(SuppressionRequest $request): ?SuppressionReceipt
    {
        throw new LogicException('No suppression provider is bound.');
    }

    public function inspect(SuppressionRequest $request): ?SuppressionReceipt
    {
        throw new LogicException('No suppression provider is bound.');
    }
}
