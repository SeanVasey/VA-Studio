<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipRedemptionWorkflowLock;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Models\User;
use WeakMap;

/** The fixed, unmounted synchronous workflow; all permits belong to this exact assembly. */
final class MemberGrantActivations
{
    private MemberOriginalSourceCapture $sources;

    private MemberOriginalArtifacts $artifacts;

    private MemberLicenseAuthorizations $authorizations;

    private SealedMemberGrants $grants;

    private MemberActivationReads $reads;

    private static ?WeakMap $entries = null;

    private static ?WeakMap $completions = null;

    private static ?WeakMap $readPermits = null;

    private static ?WeakMap $workflowLocks = null;

    public function __construct(SealedMemberGrantFacts $facts, MembershipReservations $reservations,
        MemberOriginalRendererProcess $renderer, MemberOriginalFiles $files)
    {
        [$this->sources, $this->artifacts, $this->authorizations, $this->grants] = MemberOriginalSourceCapture::activationAssembly($this, $facts, $reservations, $renderer, $files);
        $this->reads = new MemberActivationReads($this, new MemberRetainedOriginals($files), $this->sources);
    }

    public function authorizations(): MemberLicenseAuthorizations
    {
        return $this->authorizations;
    }

    public function artifactAuthority(): MemberOriginalArtifacts
    {
        return $this->artifacts;
    }

    public function grantAuthority(): SealedMemberGrants
    {
        return $this->grants;
    }

    public function activate(string $definitionId, string $redemptionId, MemberLicenseAuthorization $authorization,
        ProductionCustomerPrincipal $principal, User $actor): MemberGrantActivationResult
    {
        $entry = MemberActivationEntryPermit::value();
        self::$entries ??= new WeakMap;
        self::$entries[$entry] = [$this, $this->sources, $this->authorizations, $authorization, $definitionId, $redemptionId, $principal, $actor, null, null];
        $claim = $budget = $completion = null;
        $lock = null;
        $effectCommitted = false;
        try {
            $claim = $this->authorizations->claim($authorization, $entry, $principal, $actor);
            $budget = $this->authorizations->budget($claim, $this);
            $a = $this->authorizations->authorization($claim, $this);
            $data = self::$entries[$entry];
            $data[8] = $claim;
            $data[9] = $budget;
            self::$entries[$entry] = $data;
            try {
                $primary = MemberOriginalIoFrame::captureIdle()->identityPrimary();
                $lock = MembershipRedemptionWorkflowLock::acquire($primary, $redemptionId, $budget->deadlineNs());
            } catch (MembershipException $error) {
                throw new MemberGrantException($error->reason === 'operation_deadline' ? 'source_deadline' : 'source_refused', previous: $error);
            }
            self::$workflowLocks ??= new WeakMap;
            self::$workflowLocks[$budget] = [$this, $this->reads, $lock];
            // Current owner and complete retained history precede every live-only source gate.
            $read = $this->readPermit($definitionId, $redemptionId, $principal, $actor, $budget, 'probe', $a);
            $winner = $this->reads->probe($read, $definitionId, $redemptionId, $principal, $actor, $budget, $a);
            if ($winner !== null) {
                return $winner;
            }
            $source = $this->sources->captureForActivation($entry, $definitionId, $redemptionId, $principal, $actor, $claim, $budget);
            $idle = MemberOriginalIoFrame::captureIdle();
            $materialized = $this->artifacts->prepare($source, $idle);
            $this->artifacts->verifyCurrentBytes($materialized, $idle);
            $completion = MemberActivationCompletionPermit::value();
            self::$completions ??= new WeakMap;
            self::$completions[$completion] = [$this, $this->sources, $this->reads, $materialized, $definitionId, $redemptionId, $principal, $actor, $claim, $budget];
            $readback = $this->sources->completeActivation($completion, $this->reads, $materialized, $definitionId, $redemptionId, $principal, $actor, $claim, $budget);
            $effectCommitted = true;
            $read = $this->readPermit($definitionId, $redemptionId, $principal, $actor, $budget, 'verify', $readback);

            return $this->reads->verify($read, $definitionId, $redemptionId, $principal, $actor, $budget, $readback);
        } catch (\Throwable $error) {
            if ($effectCommitted) {
                throw new MemberGrantException('committed_activation_receipt_refused', previous: $error);
            }
            throw $error;
        } finally {
            try {
                $lock?->closeAfterRefusal();
            } catch (\Throwable $error) {
                throw new MemberGrantException($effectCommitted ? 'committed_activation_receipt_refused' : 'source_refused', previous: $error);
            } finally {
                unset(self::$entries[$entry]);
                if ($completion !== null && self::$completions !== null) {
                    unset(self::$completions[$completion]);
                }
                if ($claim !== null) {
                    $this->authorizations->retire($claim);
                }
                if ($budget !== null) {
                    if (self::$workflowLocks !== null) {
                        unset(self::$workflowLocks[$budget]);
                    }
                    $this->sources->retireWorkflow($budget);
                    $this->artifacts->retireWorkflow($budget);
                    $this->retireReads($budget);
                }
            }
        }
    }

    public function readActivated(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): MemberGrantActivationResult
    {
        $budget = MemberOriginalBudget::start();
        $permit = $this->readPermit($definitionId, $redemptionId, $principal, $actor, $budget, 'read', null);
        try {
            return $this->reads->read($permit, $definitionId, $redemptionId, $principal, $actor, $budget);
        } finally {
            $this->retireReads($budget);
        }
    }

    public function assertEntry(MemberActivationEntryPermit $permit, MemberLicenseAuthorizations $issuer,
        MemberLicenseAuthorization $ticket, string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): void
    {
        MemberGrantException::require((self::$entries[$permit] ?? null) === [$this, $this->sources, $issuer, $ticket, $definitionId, $redemptionId, $principal, $actor, null, null]
            && $issuer === $this->authorizations, 'foreign_activation_entry');
    }

    public static function consumeSourceEntry(MemberActivationEntryPermit $permit, ?self $owner, MemberOriginalSourceCapture $sources,
        string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor,
        MemberLicenseAuthorizationClaim $claim, MemberOriginalBudget $budget): void
    {
        $data = self::$entries[$permit] ?? null;
        if (self::$entries !== null) {
            unset(self::$entries[$permit]);
        }
        MemberGrantException::require($owner !== null && is_array($data) && $data[0] === $owner && $data[1] === $sources
            && array_slice($data, 4) === [$definitionId, $redemptionId, $principal, $actor, $claim, $budget], 'foreign_activation_entry');
        $budget->assertCurrent();
    }

    public static function consumeCompletion(MemberActivationCompletionPermit $permit, ?self $owner, MemberOriginalSourceCapture $sources,
        MemberActivationReads $reads, MemberOriginalMaterialization $materialized, string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal,
        User $actor, MemberLicenseAuthorizationClaim $claim, MemberOriginalBudget $budget): void
    {
        $data = self::$completions[$permit] ?? null;
        if (self::$completions !== null) {
            unset(self::$completions[$permit]);
        }
        MemberGrantException::require($owner !== null && is_array($data)
            && $data === [$owner, $sources, $reads, $materialized, $definitionId, $redemptionId, $principal, $actor, $claim, $budget]
            && $owner->reads === $reads, 'foreign_completion');
        $budget->assertCurrent();
    }

    private function readPermit(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor,
        MemberOriginalBudget $budget, string $kind, array|MemberGrantActivationResult|MemberActivationCompletedReadback|null $expected): MemberActivationReadPermit
    {
        $permit = MemberActivationReadPermit::value();
        self::$readPermits ??= new WeakMap;
        self::$readPermits[$permit] = [$this, $this->reads, $definitionId, $redemptionId, $principal, $actor, $budget, $kind, $expected];

        return $permit;
    }

    public static function consumeReadPermit(MemberActivationReadPermit $permit, self $owner, MemberActivationReads $reads,
        string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget,
        string $kind, array|MemberGrantActivationResult|MemberActivationCompletedReadback|null $expected): ?MembershipRedemptionWorkflowLock
    {
        $data = self::$readPermits[$permit] ?? null;
        if (self::$readPermits !== null) {
            unset(self::$readPermits[$permit]);
        }
        MemberGrantException::require($data === [$owner, $reads, $definitionId, $redemptionId, $principal, $actor, $budget, $kind, $expected], 'foreign_historical_read');
        $budget->assertCurrent();
        $lock = self::$workflowLocks[$budget] ?? null;
        MemberGrantException::require($lock === null || (is_array($lock) && array_slice($lock, 0, 2) === [$owner, $reads]), 'foreign_historical_read');

        return $lock[2] ?? null;
    }

    private function retireReads(MemberOriginalBudget $budget): void
    {
        if (self::$readPermits !== null) {
            foreach (self::$readPermits as $permit => $data) {
                if ($data[0] === $this && $data[6] === $budget) {
                    unset(self::$readPermits[$permit]);
                }
            }
        }
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_activation_owner');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_activation_owner');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_activation_owner');
    }
}
