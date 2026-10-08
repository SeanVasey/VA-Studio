<?php

namespace App\Domain\Memberships\Production;

/** Producer-owned, nonserializable token captured from actual verified invoice rows on the current primary. */
interface MembershipPaidInvoiceProof extends \JsonSerializable
{
    public function binding(): MembershipInvoiceBinding;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
