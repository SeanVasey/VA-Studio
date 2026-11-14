<?php

namespace App\Domain\Memberships\Billing;

/** Value only. BillingLedger separately retains issuer, original primary and exact allocation. */
final readonly class BillingRetrievalStart implements \JsonSerializable
{
    public function __construct(public int $position, public string $allocationToken, public string $retrievalToken, public string $databaseAt) {}

    public function __serialize(): never
    {
        throw new BillingException('foreign_retrieval');
    }

    public function jsonSerialize(): never
    {
        throw new BillingException('foreign_retrieval');
    }
}
