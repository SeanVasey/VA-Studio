<?php

namespace App\Domain\Memberships\Production;

/** A policy hash alone or a browser benefit map is not approved policy authority. */
interface MembershipPolicyFactsProof extends \JsonSerializable
{
    public function binding(): MembershipPolicyBinding;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
