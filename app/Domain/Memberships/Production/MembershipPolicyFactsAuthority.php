<?php

namespace App\Domain\Memberships\Production;

/** A frozen seller-approved policy source; this child installs no such provider. */
interface MembershipPolicyFactsAuthority
{
    public function lock(string $planVersion, MembershipRows $rows): MembershipPolicyFactsProof;

    public function proveCurrent(MembershipPolicyFactsProof $proof, MembershipRows $rows): void;
}
