<?php

namespace App\Domain\Customers\ProductionFeatures\Suppression;

/** Root binds a separately reviewed, authenticated, scope-specific adapter. None is bound here. */
interface ProductionSuppressionProvider
{
    /**
     * Hash of the exact reviewed provider binding this adapter is authenticated for, or null when unbound.
     *
     * Must be pure and configuration-only: no network, filesystem, database or credential-store I/O. The runtime
     * calls it several times per entry point, including inside the held transaction at the commit fence, so any
     * I/O here would run under that transaction and its deadline.
     */
    public function boundTo(): ?string;

    /** At most one invocation per durable attempt, after its commit. Its outcome never confirms anything. */
    public function suppress(ProductionSuppressionRequest $request): void;

    /** Read-only provider lookup; never resends. Positive only with an exact scoped receipt. */
    public function inspect(ProductionSuppressionRequest $request): ?ProductionSuppressionReceipt;
}
