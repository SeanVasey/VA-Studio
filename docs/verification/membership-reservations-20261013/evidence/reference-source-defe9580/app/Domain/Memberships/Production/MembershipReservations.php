<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Models\User;
use WeakMap;

/** Issuer-owned, same-frame reservation proofs. Only the owned operation may create a reservation. */
final class MembershipReservations implements MembershipReservationAuthority
{
    private WeakMap $issued;

    public function __construct()
    {
        $this->issued = new WeakMap;
    }

    public function __clone(): void
    {
        throw new MembershipException('clone_refused');
    }

    public function reserve(MembershipPaidInvoiceProof $invoice, MembershipEligibleLicenseProof $license, string $requestKey,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof
    {
        // The write-owning constructor consumed its separate entry permit before touching source data.
        // This permit allows one token for its fully audited retained effect, never another write.
        $context = MembershipReservationContext::consumeReservationPermit($this, $invoice, $license, $requestKey, $principal, $actor, $rows);

        return $this->issue($context, $principal, $actor, $rows);
    }

    public function lock(string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof
    {
        $context = MembershipReservationContext::inspect($redemptionId, $principal, $actor, $rows);

        return $this->issue($context, $principal, $actor, $rows);
    }

    public function proveCurrent(MembershipReservationProof $proof, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): void
    {
        MembershipException::require($proof instanceof MembershipReservationToken && isset($this->issued[$proof]), 'foreign_reservation_proof');
        $issued = $this->issued[$proof];
        MembershipException::require($issued['rows'] === $rows && $issued['pdo'] === $rows->identity()
            && $issued['principal'] === $principal && $issued['actor'] === $actor, 'foreign_reservation_proof');
        MembershipException::require($issued['context']->reservationBinding() == $proof->binding(), 'changed_reservation_proof');
        $issued['context']->proveCurrent();
    }

    private function issue(MembershipReservationContext $context, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof
    {
        $proof = MembershipReservationToken::value($context->reservationBinding());
        $this->issued[$proof] = ['context' => $context, 'rows' => $rows, 'pdo' => $rows->identity(), 'principal' => $principal, 'actor' => $actor];

        return $proof;
    }
}
