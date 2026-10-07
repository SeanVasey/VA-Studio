<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

/** Root binds a separately reviewed, authenticated, scope-specific adapter. None is bound here. */
interface ProductionSuppressionProvider
{
    /** Hash of the exact reviewed provider binding this adapter is authenticated for, or null when unbound. */
    public function boundTo(): ?string;

    /** At most one invocation per durable attempt, after its commit. Its outcome never confirms anything. */
    public function suppress(ProductionSuppressionRequest $request): void;

    /** Read-only provider lookup; never resends. Positive only with an exact scoped receipt. */
    public function inspect(ProductionSuppressionRequest $request): ?ProductionSuppressionReceipt;
}
