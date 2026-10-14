<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Grants\Member\MemberActivationEffectPermit;
use App\Domain\Grants\Member\MemberOriginalCapturePermit;
use App\Domain\Grants\Member\MemberOriginalSourceCapture;
use App\Models\User;
use WeakMap;

/** Issuer-owned, same-frame reservation proofs. Only the owned operation may create a reservation. */
final class MembershipReservations implements MembershipReservationAuthority
{
    private WeakMap $issued;

    private WeakMap $memberCaptures;

    private WeakMap $activationCaptures;

    private static ?WeakMap $activationInspectionPermits = null;

    private static ?WeakMap $activationContextPermits = null;

    private static ?WeakMap $transitionPermits = null;

    private static ?WeakMap $memberInspectionPermits = null;

    private static ?WeakMap $memberContextPermits = null;

    public function __construct()
    {
        $this->issued = new WeakMap;
        $this->memberCaptures = new WeakMap;
        $this->activationCaptures = new WeakMap;
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

    public function beginActivationCapture(MembershipActivationCapturePermit $permit, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipActivationCapture
    {
        $deadline = MemberOriginalSourceCapture::consumeActivationReservationPermit($permit, $this, $redemptionId, $principal, $actor, $rows);
        $capture = MembershipActivationCapture::value();
        self::$activationInspectionPermits ??= new WeakMap;
        self::$activationInspectionPermits[$capture] = [$redemptionId, $principal, $actor, $rows, $rows->identity(), $deadline];
        try {
            $context = MembershipReservationContext::inspectMember($capture, $redemptionId, $principal, $actor, $rows, $deadline);
        } finally {
            unset(self::$activationInspectionPermits[$capture]);
        }
        self::$activationContextPermits ??= new WeakMap;
        self::$activationContextPermits[$capture] = [$context, $rows, $rows->identity()];
        try {
            $context->bindMemberCapture($capture);
        } finally {
            unset(self::$activationContextPermits[$capture]);
        }
        $proof = $this->issue($context, $principal, $actor, $rows);
        $this->activationCaptures[$capture] = ['context' => $context, 'rows' => $rows, 'pdo' => $rows->identity(),
            'principal' => $principal, 'actor' => $actor, 'deadline' => $deadline, 'state' => 'current',
            'intent' => $context->memberIntent($capture, $proof), 'proof' => $proof, 'frame' => null];

        return $capture;
    }

    public static function consumeActivationInspectionPermit(MembershipActivationCapture $capture, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows, int $deadline): void
    {
        $permit = self::$activationInspectionPermits[$capture] ?? null;
        if (self::$activationInspectionPermits !== null) {
            unset(self::$activationInspectionPermits[$capture]);
        }
        MembershipException::require($permit === [$redemptionId, $principal, $actor, $rows, $rows->identity(), $deadline], 'foreign_member_capture');
    }

    public static function consumeActivationContextPermit(MembershipActivationCapture $capture, MembershipReservationContext $context, MembershipRows $rows): void
    {
        $permit = self::$activationContextPermits[$capture] ?? null;
        if (self::$activationContextPermits !== null) {
            unset(self::$activationContextPermits[$capture]);
        }
        MembershipException::require($permit === [$context, $rows, $rows->identity()], 'foreign_member_capture');
    }

    public function activationSourceValues(MembershipActivationCapture $capture, MembershipRows $rows): array
    {
        return $this->activationCurrent($capture, $rows)['context']->memberSourceValues($capture);
    }

    public function activationIntent(MembershipActivationCapture $capture, MembershipRows $rows): MemberGrantIntent
    {
        return $this->activationCurrent($capture, $rows)['intent'];
    }

    /** Value from the exact registered capture, valid only until retirement; never a renewed lease. */
    public function activationHonorDeadline(MembershipActivationCapture $capture): int
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry), 'foreign_member_capture');

        return $entry['context']->memberHonorDeadline($capture);
    }

    public function activationCurrentCallbacks(MembershipActivationCapture $capture, MembershipRows $rows): void
    {
        $this->activationCurrent($capture, $rows)['context']->memberCurrentCallbacks($capture);
    }

    public function activationCurrentRaw(MembershipActivationCapture $capture, MembershipRows $rows): void
    {
        $this->activationCurrent($capture, $rows)['context']->memberCurrentRaw($capture);
    }

    public function activationCommittedCallbacks(MembershipActivationCapture $capture, IdentityCommittedFrame $frame): void
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'current'
            && $frame->reader()->identityPrimary() === $entry['pdo'] && hrtime(true) < $entry['deadline'], 'foreign_member_capture');
        $entry['state'] = 'committed';
        $entry['frame'] = $frame;
        $this->activationCaptures[$capture] = $entry;
        $entry['context']->memberCommittedCallbacks($capture, $frame);
    }

    public function activationCommittedRaw(MembershipActivationCapture $capture, IdentityCommittedFrame $frame): void
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'committed'
            && $entry['frame'] === $frame && hrtime(true) < $entry['deadline'], 'foreign_member_capture');
        $entry['state'] = 'finished';
        $this->activationCaptures[$capture] = $entry;
        $entry['context']->memberCommittedRaw($capture, $frame);
    }

    public function activationPostFinish(MembershipActivationCapture $capture, IdentityCommittedFrame $frame): void
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'finished' && $entry['frame'] === $frame
            && ! $entry['pdo']->inTransaction(), 'foreign_member_capture');
        $entry['state'] = 'closed';
        $this->activationCaptures[$capture] = $entry;
        $entry['context']->memberPostFinish($capture);
    }

    /** Pure retained values only; the completion owner separately requires its successful tail. */
    public function activationRetainedState(MembershipActivationCapture $capture, \PDO $primary): array
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'closed'
            && $entry['pdo'] === $primary && ! $primary->inTransaction()
            && hrtime(true) < $entry['deadline'], 'foreign_member_capture');

        return $entry['context']->activationRetainedState($capture);
    }

    public function retireActivationCapture(MembershipActivationCapture $capture): void
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry), 'foreign_member_capture');
        unset($this->activationCaptures[$capture], $this->issued[$entry['proof']]);
        $entry['context']->retireMemberCapture($capture);
    }

    private function activationCurrent(MembershipActivationCapture $capture, MembershipRows $rows): array
    {
        $entry = $this->activationCaptures[$capture] ?? null;
        MembershipException::require(is_array($entry) && $entry['state'] === 'current' && $entry['rows'] === $rows
            && $entry['pdo'] === $rows->identity() && hrtime(true) < $entry['deadline'], 'foreign_member_capture');

        return $entry;
    }

    public function activationValues(MembershipActivationCapture $capture, MembershipRows $rows): array
    {
        return $this->activationCurrent($capture, $rows)['context']->activationValues($capture);
    }

    public function planActivation(MembershipActivationCapture $capture, MembershipRows $rows,
        MemberActivationEffectPermit $permit, array $plan): MembershipActivationTransition
    {
        $entry = $this->activationCurrent($capture, $rows);
        MemberOriginalSourceCapture::consumeReservationEffect($permit, $this, $capture, $rows, $plan);
        $transition = MembershipActivationTransition::value();
        self::$transitionPermits ??= new WeakMap;
        self::$transitionPermits[$transition] = [$capture, $entry['context'], $rows, $rows->identity(), $plan];
        try {
            $entry['context']->planActivation($capture, $transition, $plan);
        } finally {
            unset(self::$transitionPermits[$transition]);
        }

        return $transition;
    }

    public static function consumeTransitionPermit(MembershipActivationCapture $capture, MembershipActivationTransition $transition,
        MembershipReservationContext $context, MembershipRows $rows, array $plan): void
    {
        $entry = self::$transitionPermits[$transition] ?? null;
        if (self::$transitionPermits !== null) {
            unset(self::$transitionPermits[$transition]);
        }
        MembershipException::require($entry === [$capture, $context, $rows, $rows->identity(), $plan], 'foreign_activation_transition');
    }

    public function beginActivationInsert(MembershipActivationCapture $capture, MembershipActivationTransition $transition, MembershipRows $rows): void
    {
        $this->activationCurrent($capture, $rows)['context']->beginActivationInsert($capture, $transition);
    }

    public function proveInsertedActivation(MembershipActivationCapture $capture, MembershipActivationTransition $transition, MembershipRows $rows): void
    {
        $this->activationCurrent($capture, $rows)['context']->proveInsertedActivation($capture, $transition);
    }

    private function issue(MembershipReservationContext $context, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipReservationProof
    {
        $proof = MembershipReservationToken::value($context->reservationBinding());
        $this->issued[$proof] = ['context' => $context, 'rows' => $rows, 'pdo' => $rows->identity(), 'principal' => $principal, 'actor' => $actor];

        return $proof;
    }
}
