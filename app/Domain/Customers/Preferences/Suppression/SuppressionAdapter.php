<?php

namespace App\Domain\Customers\Preferences\Suppression;

/** Reviewed authenticated scope-specific adapter; suppress has one invocation, inspect never resends. */
interface SuppressionAdapter
{
    public function boundTo(): ?string;

    public function suppress(SuppressionRequest $request): ?SuppressionReceipt;

    public function inspect(SuppressionRequest $request): ?SuppressionReceipt;
}
