<?php

namespace App\Domain\Memberships\Production;

/** Actual original/asset readiness on the captured primary; no flag-only or pending receipt. */
interface MemberGrantReceipt extends \JsonSerializable
{
    public function binding(): MemberGrantBinding;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
