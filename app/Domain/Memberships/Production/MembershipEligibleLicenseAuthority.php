<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;

/** Exact current T25 license/assets/terms closure with its original readiness deadline. */
interface MembershipEligibleLicenseAuthority
{
    public function lock(string $selection, int $expectedRevision, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipEligibleLicenseProof;

    public function proveCurrent(MembershipEligibleLicenseProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void;
}
