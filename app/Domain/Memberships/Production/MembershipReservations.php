<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberOriginalCapturePermit;
use App\Domain\Grants\Member\MemberOriginalSourceCapture;
use App\Models\User;
use WeakMap;

/** Issuer-owned, same-frame reservation proofs. Only the owned operation may create a reservation. */
final class MembershipReservations implements MembershipReservationAuthority
{
    private WeakMap $issued;

    private WeakMap $memberCaptures;

    private static ?WeakMap $memberInspectionPermits = null;

    private static ?WeakMap $memberContextPermits = null;

    public function __construct()
    {
        $this->issued = new WeakMap;
        $this->memberCaptures = new WeakMap;
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

    public function beginMemberCapture(MemberOriginalCapturePermit $permit, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipMemberCapture
    {
        $deadline = MemberOriginalSourceCapture::consumeReservationPermit($permit, $this, $redemptionId, $principal, $actor, $rows);
        $capture = MembershipMemberCapture::value();
        self::$memberInspectionPermits ??= new WeakMap;
        self::$memberInspectionPermits[$capture] = [$redemptionId, $principal, $actor, $rows, $rows->identity(), $deadline];
        try {
            $context = MembershipReservationContext::inspectMember($capture, $redemptionId, $principal, $actor, $rows, $deadline);
        } finally {
            unset(self::$memberInspectionPermits[$capture]);
        }
        self::$memberContextPermits ??= new WeakMap;
        self::$memberContextPermits[$capture] = [$context, $rows, $rows->identity()];
        try {
            $context->bindMemberCapture($capture);
        } finally {
            unset(self::$memberContextPermits[$capture]);
        }
        $proof = $this->issue($context, $principal, $actor, $rows);
        $this->memberCaptures[$capture] = ['context' => $context, 'rows' => $rows, 'pdo' => $rows->identity(),
            'principal' => $principal, 'actor' => $actor, 'deadline' => $deadline, 'state' => 'current',
            'intent' => $context->memberIntent($capture, $proof), 'proof' => $proof, 'frame' => null];

        return $capture;
    }

    public static function consumeMemberInspectionPermit(MembershipMemberCapture $capture, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows, int $deadline): void
    {
        $permit = self::$memberInspectionPermits[$capture] ?? null;
        if (self::$memberInspectionPermits !== null) {
            unset(self::$memberInspectionPermits[$capture]);
        }
        MembershipException::require($permit === [$redemptionId, $principal, $actor, $rows, $rows->identity(), $deadline], 'foreign_member_capture');
    }

    public static function consumeMemberContextPermit(MembershipMemberCapture $capture, MembershipReservationContext $context, MembershipRows $rows): void
    {
        $permit = self::$memberContextPermits[$capture] ?? null;
        if (self::$memberContextPermits !== null) {
            unset(self::$memberContextPermits[$capture]);
        }
        MembershipException::require($permit === [$context, $rows, $rows->identity()], 'foreign_member_capture');
    }

    public function memberSourceValues(MembershipMemberCapture $capture, MembershipRows $rows): array
    {
        return $this->memberCurrent($capture, $rows)['context']->memberSourceValues($capture);
    }

    public function memberIntent(MembershipMemberCapture $capture, MembershipRows $rows): MemberGrantIntent
    {
        return $this->memberCurrent($capture, $rows)['intent'];
    }

    /** Value from the exact registered capture, valid only until retirement; never a renewed lease. */
    public function memberHonorDeadline(MembershipMemberCapture $capture): int
    {
        $entry = $this->memberCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry), 'foreign_member_capture');

        return $entry['context']->memberHonorDeadline($capture);
    }

    public function memberCurrentCallbacks(MembershipMemberCapture $capture, MembershipRows $rows): void
    {
        $this->memberCurrent($capture, $rows)['context']->memberCurrentCallbacks($capture);
    }

    public function memberCurrentRaw(MembershipMemberCapture $capture, MembershipRows $rows): void
    {
        $this->memberCurrent($capture, $rows)['context']->memberCurrentRaw($capture);
    }

    public function memberCommittedCallbacks(MembershipMemberCapture $capture, IdentityCommittedFrame $frame): void
    {
        $entry = $this->memberCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'current'
            && $frame->reader()->identityPrimary() === $entry['pdo'] && hrtime(true) < $entry['deadline'], 'foreign_member_capture');
        $entry['state'] = 'committed';
        $entry['frame'] = $frame;
        $this->memberCaptures[$capture] = $entry;
        $entry['context']->memberCommittedCallbacks($capture, $frame);
    }

    public function memberCommittedRaw(MembershipMemberCapture $capture, IdentityCommittedFrame $frame): void
    {
        $entry = $this->memberCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'committed'
            && $entry['frame'] === $frame && hrtime(true) < $entry['deadline'], 'foreign_member_capture');
        $entry['state'] = 'finished';
        $this->memberCaptures[$capture] = $entry;
        $entry['context']->memberCommittedRaw($capture, $frame);
    }

    public function memberPostFinish(MembershipMemberCapture $capture, IdentityCommittedFrame $frame): void
    {
        $entry = $this->memberCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'finished' && $entry['frame'] === $frame
            && ! $entry['pdo']->inTransaction(), 'foreign_member_capture');
        $entry['state'] = 'closed';
        $this->memberCaptures[$capture] = $entry;
        $entry['context']->memberPostFinish($capture);
    }

    public function retireMemberCapture(MembershipMemberCapture $capture): void
    {
        $entry = $this->memberCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry), 'foreign_member_capture');
        unset($this->memberCaptures[$capture], $this->issued[$entry['proof']]);
        $entry['context']->retireMemberCapture($capture);
    }

    private function memberCurrent(MembershipMemberCapture $capture, MembershipRows $rows): array
    {
        $entry = $this->memberCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'current' && $entry['rows'] === $rows
            && $entry['pdo'] === $rows->identity() && hrtime(true) < $entry['deadline'], 'foreign_member_capture');

        return $entry;
    }

    private function issue(MembershipReservationContext $context, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof
    {
        $proof = MembershipReservationToken::value($context->reservationBinding());
        $this->issued[$proof] = ['context' => $context, 'rows' => $rows, 'pdo' => $rows->identity(), 'principal' => $principal, 'actor' => $actor];

        return $proof;
    }
}
