<?php

namespace App\Domain\Customers\ProductionIdentity;

use JsonSerializable;

/** Opaque sibling-specific seal. Only the exact object retained by its receipt is accepted. */
final readonly class IdentityHistoricalCommitSeal implements JsonSerializable
{
    private function __construct(private IdentityHistoricalCommittedReceipt $receipt,
        private IdentityOriginalCommitWitness $witness, private int $originalDeadlineNs) {}

    /** @internal Minting another opaque object cannot replace the exact retained receipt seal. */
    public static function capture(IdentityHistoricalCommittedReceipt $receipt, IdentityOriginalCommitWitness $witness, int $originalDeadlineNs): self
    {
        $witness->assertHeld();
        if (! $receipt->belongsTo($witness, $witness->reader(), $originalDeadlineNs)) {
            throw new IdentityException('historical_commit_required');
        }

        return new self($receipt, $witness, $originalDeadlineNs);
    }

    public function matches(IdentityHistoricalCommittedReceipt $receipt, IdentityOriginalCommitWitness $witness, int $deadlineNs): bool
    {
        return $receipt === $this->receipt && $witness === $this->witness && $deadlineNs === $this->originalDeadlineNs;
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new IdentityException('historical_commit_required');
    }

    public function jsonSerialize(): never
    {
        throw new IdentityException('historical_commit_required');
    }

    public function __debugInfo(): array
    {
        return ['original_commit_seal' => true];
    }
}
