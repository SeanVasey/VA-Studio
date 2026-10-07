<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;

/** Distinct member-purpose original family. Pending preparation is never activation readiness. */
interface MemberGrantAuthority
{
    public function prepare(MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberGrantReceipt;

    public function proveReadyCurrent(MemberGrantReceipt $receipt, MemberGrantIntent $intent, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void;
}
