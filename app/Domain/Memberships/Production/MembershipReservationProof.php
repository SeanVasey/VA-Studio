<?php

namespace App\Domain\Memberships\Production;

/** Issued only by the membership consumer from exact retained reservation/redemption rows. */
interface MembershipReservationProof extends \JsonSerializable
{
    public function binding(): MembershipReservationBinding;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
