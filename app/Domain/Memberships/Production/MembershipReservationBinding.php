<?php

namespace App\Domain\Memberships\Production;

/** Immutable nonsecret source value. It is never an authority token or a from-HTTP mint. */
final readonly class MembershipReservationBinding
{
    public function __construct(
        public string $redemptionId,
        public string $periodId,
        public string $reservationId,
        public string $intentHash,
        public int $creditAmount,
        public string $honorDeadline,
        public array $originalBuyerBinding,
    ) {
        foreach ([$redemptionId, $periodId, $reservationId] as $id) {
            MembershipValues::id($id);
        }
        MembershipValues::hash($intentHash);
        MembershipValues::utc($honorDeadline);
        MembershipValues::buyer($originalBuyerBinding);
        MembershipException::require($creditAmount > 0, 'invalid_credit_amount');
    }
}
