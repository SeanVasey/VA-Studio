<?php

namespace App\Domain\Grants\PaidTax;

/** Exact original scalar window intersected with the same request's live min-only ceiling. */
final readonly class PaidTaxSnapshotDeadline
{
    public function __construct(private int $original, private ?PaidTaxRequestBudget $requestBudget) {}

    public function value(): int
    {
        return min($this->original, $this->requestBudget?->value() ?? $this->original);
    }

    public function proveCurrent(): void
    {
        PaidTaxException::require(hrtime(true) <= $this->value(), 410);
    }

    public function __serialize(): never
    {
        throw new \LogicException('Tax snapshot deadlines cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new \LogicException('Tax snapshot deadlines cannot be deserialized.');
    }

    private function __clone() {}
}
