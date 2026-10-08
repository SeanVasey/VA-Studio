<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;

/** Reserve actual derived benefits before minting an internal same-origin proof. No caller totals. */
interface MembershipReservationAuthority
{
    public function reserve(MembershipPaidInvoiceProof $invoice, MembershipEligibleLicenseProof $license, string $requestKey,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof;

    public function lock(string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof;

    public function proveCurrent(MembershipReservationProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void;
}
