<?php

namespace App\Domain\Memberships\Production;

/** Eligibility arrays, an old page snapshot and caller evidence cannot mint this source token. */
interface MembershipEligibleLicenseProof extends \JsonSerializable
{
    public function binding(): MembershipLicenseBinding;

    public function __serialize(): never;

    public function jsonSerialize(): never;
}
