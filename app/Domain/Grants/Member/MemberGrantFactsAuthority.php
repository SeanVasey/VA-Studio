<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;

/** Actual reviewed member-specific original terms/profile facts; never paid/free/test adoption. */
interface MemberGrantFactsAuthority
{
    public function lock(string $definitionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantFactsProof;

    public function proveCurrent(MemberGrantFactsProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void;
}
