<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;

/** Private materialization/readiness capability; a metadata row or claimed path is never ready. */
interface MemberOriginalArtifactAuthority
{
    public function prepare(MemberGrantIntent $intent, MemberGrantFactsProof $facts, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberOriginalArtifactReceipt;

    public function proveReadyCurrent(MemberOriginalArtifactReceipt $receipt, MemberGrantIntent $intent, MemberGrantFactsProof $facts, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void;
}
