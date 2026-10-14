<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MemberGrantIntent;
use App\Domain\Memberships\Production\MembershipActivationCapture;
use App\Domain\Memberships\Production\MembershipActivationCapturePermit;
use App\Domain\Memberships\Production\MembershipPolicyBinding;
use App\Domain\Memberships\Production\MembershipReservations;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Throwable;
use WeakMap;

/** Two owned source inspections around detached I/O; detached tickets never retain a live frame. */
final class MemberOriginalSourceCapture
{
    private MemberOriginalArtifacts $artifacts;

    private ?MemberGrantActivations $activationOwner = null;

    private ?MemberLicenseAuthorizations $authorizations = null;

    private ?SealedMemberGrants $grants = null;

    private static ?WeakMap $activationInspectionPermits = null;

    private static ?WeakMap $activationReservationPermits = null;

    private static ?WeakMap $effectPermits = null;

    private static ?WeakMap $artifactPlanPermits = null;

    private ?WeakMap $completedReadbacks = null;

    private WeakMap $workflows;

    private WeakMap $sources;

    private WeakMap $claims;

    private WeakMap $active;

    private static ?WeakMap $inspectionPermits = null;

    private static ?WeakMap $reservationPermits = null;

    private function __construct(private readonly SealedMemberGrantFacts $facts, private readonly MembershipReservations $reservations)
    {
        $this->workflows = new WeakMap;
        $this->sources = new WeakMap;
        $this->claims = new WeakMap;
        $this->active = new WeakMap;
    }

    /** Fixed assembly only: constructors neither resolve dependencies nor grant source authority. */
    public static function assembly(SealedMemberGrantFacts $facts, MembershipReservations $reservations,
        MemberOriginalRendererProcess $renderer, MemberOriginalFiles $files): array
    {
        $issuer = new self($facts, $reservations);
        $issuer->artifacts = new MemberOriginalArtifacts($issuer, $facts, $renderer, $files);

        return [$issuer, $issuer->artifacts];
    }

    /** Fixed activation assembly; no configurable consumer or registration side effect. */
    public static function activationAssembly(MemberGrantActivations $owner, SealedMemberGrantFacts $facts,
        MembershipReservations $reservations, MemberOriginalRendererProcess $renderer, MemberOriginalFiles $files): array
    {
        $issuer = new self($facts, $reservations);
        $issuer->activationOwner = $owner;
        $issuer->artifacts = new MemberOriginalArtifacts($issuer, $facts, $renderer, $files);
        $issuer->authorizations = new MemberLicenseAuthorizations($owner, $issuer);
        $issuer->grants = new SealedMemberGrants($issuer);

        return [$issuer, $issuer->artifacts, $issuer->authorizations, $issuer->grants];
    }

    public function captureForReview(MemberLicenseReviewEntryPermit $entry, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget): MemberLicenseReviewCapture
    {
        MemberGrantException::require($this->authorizations !== null, 'foreign_assembly');
        MemberLicenseAuthorizations::consumeEntry($entry, $this->authorizations, $this, $definitionId, $redemptionId, null, $principal, $actor, $budget);
        $configuration = $this->activationWorkflow($definitionId, $redemptionId, $principal, $actor, $budget);
        $result = $this->inspectActivation($configuration, $definitionId, $redemptionId, $principal, $actor, $budget, $entry, null, null, null);

        return new MemberLicenseReviewCapture($result['display']);
    }

    public function captureForConfirmation(MemberLicenseConfirmationEntryPermit $entry, string $definitionId, string $redemptionId,
        MemberLicenseConfirmationInput $input, ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget): MemberLicenseConfirmationCapture
    {
        MemberGrantException::require($this->authorizations !== null, 'foreign_assembly');
        MemberLicenseAuthorizations::consumeEntry($entry, $this->authorizations, $this, $definitionId, $redemptionId, $input, $principal, $actor, $budget);
        $configuration = $this->activationWorkflow($definitionId, $redemptionId, $principal, $actor, $budget);
        $result = $this->inspectActivation($configuration, $definitionId, $redemptionId, $principal, $actor, $budget, $entry, $input, null, null);

        return new MemberLicenseConfirmationCapture($result['values'], $result['authorization'], $configuration);
    }

    private function activationWorkflow(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal,
        User $actor, MemberOriginalBudget $budget): MemberActivationConfiguration
    {
        MemberGrantException::require($this->activationOwner !== null && $this->authorizations !== null && $this->grants !== null
            && ! isset($this->workflows[$budget]), 'foreign_workflow');
        $this->resolveIdle();
        $configuration = new MemberActivationConfiguration($this->facts, $this->reservations, $this->artifacts, $this->authorizations, $this->grants);
        $this->workflows[$budget] = [$configuration, $definitionId, $redemptionId, $principal, $actor];

        return $configuration;
    }

    public function captureForActivation(MemberActivationEntryPermit $entry, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberLicenseAuthorizationClaim $claim, MemberOriginalBudget $budget): MemberOriginalSource
    {
        MemberGrantActivations::consumeSourceEntry($entry, $this->activationOwner, $this, $definitionId, $redemptionId, $principal, $actor, $claim, $budget);
        $workflow = $this->activationWorkflowEntry($definitionId, $redemptionId, $principal, $actor, $budget);
        $result = $this->inspectActivation($workflow, $definitionId, $redemptionId, $principal, $actor, $budget, $entry, null, $claim, null);
        $source = MemberOriginalSource::value();
        $this->sources[$source] = [$result['values'], $budget, $workflow, $principal, $actor];

        return $source;
    }

    public function completeActivation(MemberActivationCompletionPermit $entry, MemberActivationReads $reads, MemberOriginalMaterialization $materialized,
        string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor,
        MemberLicenseAuthorizationClaim $claim, MemberOriginalBudget $budget): MemberActivationCompletedReadback
    {
        MemberGrantActivations::consumeCompletion($entry, $this->activationOwner, $this, $reads, $materialized, $definitionId, $redemptionId, $principal, $actor, $claim, $budget);
        $workflow = $this->activationWorkflowEntry($definitionId, $redemptionId, $principal, $actor, $budget);
        $this->artifacts->assertOwned($materialized, $this, $budget);
        $result = $this->inspectActivation($workflow, $definitionId, $redemptionId, $principal, $actor, $budget, $entry, null, $claim, $materialized);
        // The owned runner returned only after its committed proof and outer idle/config/budget tail.
        $readback = MemberActivationCompletedReadback::value($result['activation']);
        $this->completedReadbacks ??= new WeakMap;
        $this->completedReadbacks[$readback] = [$this, $this->activationOwner, $reads, $result['readback']['primary'],
            $definitionId, $redemptionId, $principal, $actor, $budget, $readback, $result['activation'],
            $result['readback']['original'], $result['readback']['state']];

        return $readback;
    }

    /** Both one-use registries are consumed before accepting the exact completed baseline. */
    public function consumeCompletedReadback(MemberActivationReadPermit $permit, MemberGrantActivations $owner,
        MemberActivationReads $reads, string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal,
        User $actor, MemberOriginalBudget $budget, MemberActivationCompletedReadback $readback): array
    {
        $entry = $this->completedReadbacks[$readback] ?? null;
        if ($this->completedReadbacks !== null) {
            unset($this->completedReadbacks[$readback]);
        }
        MemberGrantActivations::consumeReadPermit($permit, $owner, $reads, $definitionId, $redemptionId,
            $principal, $actor, $budget, 'verify', $readback);
        MemberGrantException::require(is_array($entry) && array_slice($entry, 0, 3) === [$this, $owner, $reads]
            && array_slice($entry, 4, 7) === [$definitionId, $redemptionId, $principal, $actor, $budget, $readback, $readback->result()]
            && $owner === $this->activationOwner, 'foreign_completed_readback');
        $idle = MemberOriginalIoFrame::captureIdle();
        MemberGrantException::require($idle->identityPrimary() === $entry[3], 'changed_primary');
        $budget->assertCurrent();

        return [$entry[11], $entry[12], $entry[10], $entry[3]];
    }

    private function activationWorkflowEntry(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal,
        User $actor, MemberOriginalBudget $budget): MemberActivationConfiguration
    {
        $entry = $this->workflows[$budget] ?? null;
        MemberGrantException::require(is_array($entry) && $entry[0] instanceof MemberActivationConfiguration
            && array_slice($entry, 1) === [$definitionId, $redemptionId, $principal, $actor], 'foreign_workflow');
        $entry[0]->admit();
        $budget->assertCurrent();

        return $entry[0];
    }

    public function admitAuthorizationLock(MemberLicenseAuthorizations $issuer, MemberLicenseAuthorizationClaim $claim,
        MemberOriginalSourceValues $source, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MembershipPolicyBinding
    {
        $entry = $this->active[$rows] ?? null;
        MemberGrantException::require($issuer === $this->authorizations && is_array($entry) && $entry['phase'] === 'current'
            && $entry['inspection'] instanceof MemberActivationInspection, 'foreign_authorization');

        return $entry['inspection']->admitAuthorization($claim, $source, $principal, $actor, $rows);
    }

    public function capture(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal,
        User $actor, MemberOriginalBudget $budget): MemberOriginalSource
    {
        MemberOriginals::consumeEntry($this, $definitionId, $redemptionId, $principal, $actor, $budget, 'capture');
        $this->resolveIdle();
        $configuration = new MemberOriginalConfiguration($this->facts, $this->reservations, $this->artifacts);
        $this->workflows[$budget] = [$configuration, $definitionId, $redemptionId, $principal, $actor];
        $values = $this->inspect($configuration, $definitionId, $redemptionId, $principal, $actor, $budget, null);
        $source = MemberOriginalSource::value();
        $this->sources[$source] = [$values, $budget, $configuration, $principal, $actor];

        return $source;
    }

    public function complete(MemberOriginalMaterialization $materialized, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget): void
    {
        MemberOriginals::consumeEntry($this, $definitionId, $redemptionId, $principal, $actor, $budget, 'complete');
        $entry = $this->workflows[$budget] ?? null;
        MemberGrantException::require(is_array($entry) && array_slice($entry, 1) === [$definitionId, $redemptionId, $principal, $actor], 'foreign_workflow');
        $this->artifacts->assertOwned($materialized, $this, $budget);
        $this->inspect($entry[0], $definitionId, $redemptionId, $principal, $actor, $budget, $materialized);
    }

    public function claimForMaterialization(MemberOriginalSource $source, MemberOriginalArtifacts $consumer,
        MemberOriginalIoFrame $idle): MemberOriginalSourceClaim
    {
        $entry = $this->sources[$source] ?? null;
        MemberGrantException::require($consumer === $this->artifacts && is_array($entry), 'foreign_source');
        unset($this->sources[$source]);
        $claim = MemberOriginalSourceClaim::value($entry[0], $entry[1]);
        $this->claims[$claim] = $entry;
        $this->proveClaimCurrent($claim, $consumer, $idle);

        return $claim;
    }

    public function proveClaimCurrent(MemberOriginalSourceClaim $claim, MemberOriginalArtifacts $consumer,
        MemberOriginalIoFrame $idle): void
    {
        $entry = $this->claims[$claim] ?? null;
        MemberGrantException::require($consumer === $this->artifacts && is_array($entry) && $claim->values() === $entry[0]
            && $claim->budget() === $entry[1] && isset($this->workflows[$entry[1]]), 'foreign_source_claim');
        $entry[2]->admit();
        $entry[1]->assertCurrent();
        $idle->assertStillIdle();
    }

    /** Bound source/configuration check usable in a live frame; it performs no I/O or producer refresh. */
    public function assertClaim(MemberOriginalSourceClaim $claim, MemberOriginalArtifacts $consumer): void
    {
        $entry = $this->claims[$claim] ?? null;
        MemberGrantException::require($consumer === $this->artifacts && is_array($entry) && isset($this->workflows[$entry[1]])
            && $claim->values() === $entry[0] && $claim->budget() === $entry[1], 'foreign_source_claim');
        $entry[2]->admit();
        $entry[1]->assertCurrent();
    }

    public function readyInspection(MemberOriginalArtifacts $consumer, MemberGrantIntent $intent, MemberGrantFactsProof $facts,
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberOriginalInspection|MemberActivationInspection
    {
        $entry = $this->active[$rows] ?? null;
        MemberGrantException::require($consumer === $this->artifacts && is_array($entry) && $entry['phase'] === 'current', 'foreign_readiness');
        $entry['inspection']->matches($intent, $facts, $principal, $actor, $rows);
        $entry['inspection']->proveCurrent();

        return $entry['inspection'];
    }

    public function admitCommittedRegistration(MemberOriginalInspection $inspection, MemberOriginalObserver $observer): void
    {
        foreach ($this->active as $entry) {
            if ($entry['inspection'] === $inspection && $entry['observer'] === $observer && $entry['phase'] === 'current'
                && $entry['registering']) {
                return;
            }
        }
        throw new MemberGrantException('foreign_committed_proof');
    }

    private function inspect(MemberOriginalConfiguration $configuration, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget,
        ?MemberOriginalMaterialization $materialized): MemberOriginalSourceValues
    {
        $deadline = min($budget->deadlineNs(), \hrtime(true) + 60_000_000_000);
        $idle = MemberOriginalIoFrame::captureIdle();
        $configuration->admit();
        $finish = MemberOriginalFinishBoundary::capture();
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $events = $connection->getEventDispatcher();
        $originalManager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        MemberGrantException::require($originalManager === null || get_class($originalManager) === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new DatabaseTransactionsManager;
        $observer = new MemberOriginalObserver($connection, $primary, $configuration, $events, $manager);
        $idle->assertStillIdle();
        $budget->assertCurrent();
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        $inspection = null;
        $rows = null;
        $committed = false;
        try {
            try {
                $connection->beginTransaction();
                $rows = $observer->rows();
                self::$inspectionPermits ??= new WeakMap;
                self::$inspectionPermits[$rows] = [$this, $this->reservations, $this->facts, $this->artifacts,
                    $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor, $observer];
                try {
                    $inspection = new MemberOriginalInspection($this, $rows, $this->reservations, $this->facts,
                        $this->artifacts, $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor);
                } finally {
                    unset(self::$inspectionPermits[$rows]);
                }
                $this->active[$rows] = ['inspection' => $inspection, 'observer' => $observer, 'phase' => 'current', 'registering' => false];
                if ($materialized !== null) {
                    $inspection->ready($materialized);
                }
                $values = $inspection->values();
                $observer->prepare($inspection);
                $entry = $this->active[$rows];
                $entry['registering'] = true;
                $this->active[$rows] = $entry;
                try {
                    $proof = MemberOriginalCommittedProof::register($this, $inspection, $observer);
                } finally {
                    $entry['registering'] = false;
                    $this->active[$rows] = $entry;
                }
                $connection->commit();
                $committed = true;
                $observer->requireCommittedOwnership();
            } catch (Throwable $error) {
                $observer->abort();
                throw new MemberGrantException($committed ? 'committed_source_refused' : 'source_refused');
            } finally {
                // Do not overwrite callback-installed dispatcher/manager state belonging to somebody else.
                if ($connection->getEventDispatcher() === $observer) {
                    $events === null ? $connection->unsetEventDispatcher() : $connection->setEventDispatcher($events);
                }
                if ((new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection) === $manager) {
                    $originalManager === null ? $connection->unsetTransactionManager() : $connection->setTransactionManager($originalManager);
                }
            }
            try {
                unset($this->active[$rows]);
                $configuration->admit();
                $frame = IdentityCommittedFrame::begin($inspection->reader(), $deadline);
                try {
                    $proof->prove($frame);
                } finally {
                    $frame->close();
                }
            } catch (Throwable) {
                throw new MemberGrantException('committed_source_refused');
            }
        } finally {
            if ($rows !== null) {
                unset($this->active[$rows]);
            }
            $inspection?->retire();
            if ($inspection !== null) {
                $this->artifacts->retireInspection($inspection);
            }
            // All issuer registrations retaining the live frame are retired before detached I/O.
            unset($entry, $proof, $inspection, $rows, $observer);
        }
        $configuration->admit();
        $budget->assertCurrent();
        $idle->assertStillIdle();

        return $values;
    }

    private function inspectActivation(MemberActivationConfiguration $configuration, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget,
        MemberLicenseReviewEntryPermit|MemberLicenseConfirmationEntryPermit|MemberActivationEntryPermit|MemberActivationCompletionPermit $entryPermit,
        ?MemberLicenseConfirmationInput $confirmation, ?MemberLicenseAuthorizationClaim $claim,
        ?MemberOriginalMaterialization $materialized): array
    {
        $deadline = min($budget->deadlineNs(), \hrtime(true) + 60_000_000_000);
        $idle = MemberOriginalIoFrame::captureIdle();
        $configuration->admit();
        $finish = MemberOriginalFinishBoundary::capture();
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        $events = $connection->getEventDispatcher();
        $originalManager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        MemberGrantException::require($originalManager === null || get_class($originalManager) === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new DatabaseTransactionsManager;
        $observer = new MemberActivationObserver($connection, $primary, $configuration, $events, $manager);
        $idle->assertStillIdle();
        $budget->assertCurrent();
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        $inspection = null;
        $rows = null;
        $committed = false;
        try {
            try {
                $connection->beginTransaction();
                $rows = $observer->rows();
                self::$activationInspectionPermits ??= new WeakMap;
                self::$activationInspectionPermits[$rows] = [$this, $this->reservations, $this->facts, $this->artifacts,
                    $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor, $observer, $this->authorizations, $entryPermit, $confirmation];
                try {
                    $inspection = new MemberActivationInspection($this, $rows, $this->reservations, $this->facts,
                        $this->artifacts, $this->authorizations, $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor, $entryPermit, $confirmation);
                } finally {
                    unset(self::$activationInspectionPermits[$rows]);
                }
                $this->active[$rows] = ['inspection' => $inspection, 'observer' => $observer, 'phase' => 'current', 'registering' => false];
                if ($claim !== null) {
                    $inspection->authorize($claim);
                }
                if ($materialized !== null) {
                    [$intent, $factsProof, $sourceValues] = $inspection->artifactPlanSources($this);
                    $artifactPermit = MemberActivationArtifactPermit::value();
                    self::$artifactPlanPermits ??= new WeakMap;
                    self::$artifactPlanPermits[$artifactPermit] = [$this, $this->artifacts, $inspection, $materialized,
                        $intent, $factsProof, $sourceValues, $principal, $actor, $rows, $rows->identity(), $budget];
                    try {
                        $inspection->ready($artifactPermit, $materialized);
                    } finally {
                        unset(self::$artifactPlanPermits[$artifactPermit]);
                    }
                    $effect = MemberActivationEffectPermit::value();
                    self::$effectPermits ??= new WeakMap;
                    self::$effectPermits[$effect] = [$this, $inspection, $this->grants, $rows, 'inspection'];
                    try {
                        $inspection->planActivation($effect, $this->authorizations->authorization($claim, $this->activationOwner), $this->grants);
                    } finally {
                        unset(self::$effectPermits[$effect]);
                    }
                }
                $values = ['values' => $inspection->values(), 'display' => $inspection->display(),
                    'authorization' => $confirmation === null ? null : $inspection->confirmation(),
                    'activation' => $materialized === null ? null : $inspection->activationResult()];
                $observer->prepare($inspection);
                $entry = $this->active[$rows];
                $entry['registering'] = true;
                $this->active[$rows] = $entry;
                try {
                    $proof = MemberActivationCommittedProof::register($this, $inspection, $observer);
                } finally {
                    $entry['registering'] = false;
                    $this->active[$rows] = $entry;
                }
                if ($materialized !== null) {
                    $inspection->insertActivation();
                }
                $connection->commit();
                $committed = true;
                $observer->requireCommittedOwnership();
            } catch (Throwable $error) {
                // A normal afterCommit delegate can throw after the PDO commit but before return.
                // An idle captured PDO cannot justify claiming rollback of the effect.
                $committedOrIdle = $committed || ! $primary->inTransaction();
                $observer->abort();
                throw new MemberGrantException($committedOrIdle ? 'committed_activation_receipt_refused' : 'source_refused', previous: $error);
            } finally {
                // Do not overwrite callback-installed dispatcher/manager state belonging to somebody else.
                if ($connection->getEventDispatcher() === $observer) {
                    $events === null ? $connection->unsetEventDispatcher() : $connection->setEventDispatcher($events);
                }
                if ((new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection) === $manager) {
                    $originalManager === null ? $connection->unsetTransactionManager() : $connection->setTransactionManager($originalManager);
                }
            }
            try {
                unset($this->active[$rows]);
                $configuration->admit();
                $frame = IdentityCommittedFrame::begin($inspection->reader(), $deadline);
                try {
                    $proof->prove($frame);
                } finally {
                    $frame->close();
                }
                if ($materialized !== null) {
                    $values['readback'] = $inspection->completedReadback($this);
                }
            } catch (Throwable $error) {
                throw new MemberGrantException('committed_activation_receipt_refused', previous: $error);
            }
        } finally {
            if ($rows !== null) {
                unset($this->active[$rows]);
            }
            $inspection?->retire();
            if ($inspection !== null) {
                $this->artifacts->retireInspection($inspection);
            }
            // All issuer registrations retaining the live frame are retired before detached I/O.
            unset($entry, $proof, $inspection, $rows, $observer);
        }
        try {
            $configuration->admit();
            $budget->assertCurrent();
            $idle->assertStillIdle();
        } catch (Throwable $error) {
            // The physical commit has already completed, including its separate retained proof.
            throw new MemberGrantException('committed_activation_receipt_refused', previous: $error);
        }

        return $values;
    }

    public static function consumeInspection(MemberOriginalInspection $inspection, self $issuer, MembershipRows $rows,
        MembershipReservations $reservations, SealedMemberGrantFacts $facts, MemberOriginalArtifacts $artifacts,
        MemberOriginalConfiguration $configuration, MemberOriginalFinishBoundary $finish, MemberOriginalBudget $budget,
        int $deadline, string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): MemberOriginalCapturePermit
    {
        $entry = self::$inspectionPermits[$rows] ?? null;
        if (self::$inspectionPermits !== null) {
            unset(self::$inspectionPermits[$rows]);
        }
        MemberGrantException::require(is_array($entry) && array_slice($entry, 0, 12) === [$issuer, $reservations, $facts, $artifacts,
            $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor], 'owned_inspection_required');
        $permit = MemberOriginalCapturePermit::value();
        self::$reservationPermits ??= new WeakMap;
        self::$reservationPermits[$permit] = [$reservations, $redemptionId, $principal, $actor, $rows, $rows->identity(), $budget, $deadline];

        return $permit;
    }

    public static function consumeReservationPermit(MemberOriginalCapturePermit $permit, MembershipReservations $issuer,
        string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): int
    {
        $entry = self::$reservationPermits[$permit] ?? null;
        if (self::$reservationPermits !== null) {
            unset(self::$reservationPermits[$permit]);
        }
        MemberGrantException::require(is_array($entry) && array_slice($entry, 0, 6) === [$issuer, $redemptionId, $principal, $actor, $rows, $rows->identity()], 'foreign_capture_permit');
        $entry[6]->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $entry[7], 'source_deadline');

        return $entry[7];
    }

    public function admitActivationCommittedRegistration(MemberActivationInspection $inspection, MemberActivationObserver $observer): void
    {
        foreach ($this->active as $entry) {
            if ($entry['inspection'] === $inspection && $entry['observer'] === $observer && $entry['phase'] === 'current' && $entry['registering']) {
                return;
            }
        }
        throw new MemberGrantException('foreign_committed_proof');
    }

    public static function consumeActivationInspection(MemberActivationInspection $inspection, self $issuer, MembershipRows $rows,
        MembershipReservations $reservations, SealedMemberGrantFacts $facts, MemberOriginalArtifacts $artifacts,
        MemberActivationConfiguration $configuration, MemberOriginalFinishBoundary $finish, MemberOriginalBudget $budget,
        int $deadline, string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor,
        MemberLicenseAuthorizations $authorizations,
        MemberLicenseReviewEntryPermit|MemberLicenseConfirmationEntryPermit|MemberActivationEntryPermit|MemberActivationCompletionPermit $entry,
        ?MemberLicenseConfirmationInput $confirmation): MembershipActivationCapturePermit
    {
        $registered = self::$activationInspectionPermits[$rows] ?? null;
        if (self::$activationInspectionPermits !== null) {
            unset(self::$activationInspectionPermits[$rows]);
        }
        MemberGrantException::require(is_array($registered) && array_slice($registered, 0, 12) === [$issuer, $reservations, $facts, $artifacts,
            $configuration, $finish, $budget, $deadline, $definitionId, $redemptionId, $principal, $actor]
            && array_slice($registered, 13) === [$authorizations, $entry, $confirmation], 'owned_inspection_required');
        $permit = MembershipActivationCapturePermit::value();
        self::$activationReservationPermits ??= new WeakMap;
        self::$activationReservationPermits[$permit] = [$reservations, $redemptionId, $principal, $actor, $rows, $rows->identity(), $budget, $deadline];

        return $permit;
    }

    public static function consumeActivationReservationPermit(MembershipActivationCapturePermit $permit, MembershipReservations $issuer,
        string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): int
    {
        $entry = self::$activationReservationPermits[$permit] ?? null;
        if (self::$activationReservationPermits !== null) {
            unset(self::$activationReservationPermits[$permit]);
        }
        MemberGrantException::require(is_array($entry) && array_slice($entry, 0, 6) === [$issuer, $redemptionId, $principal, $actor, $rows, $rows->identity()], 'foreign_capture_permit');
        $entry[6]->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $entry[7], 'source_deadline');

        return $entry[7];
    }

    public static function consumeInspectionEffect(MemberActivationEffectPermit $permit, self $issuer,
        MemberActivationInspection $inspection, SealedMemberGrants $grants, MembershipRows $rows): void
    {
        $entry = self::$effectPermits[$permit] ?? null;
        MemberGrantException::require($entry === [$issuer, $inspection, $grants, $rows, 'inspection'], 'foreign_effect_permit');
        self::$effectPermits[$permit] = [$issuer, $inspection, $grants, $rows, 'planning'];
    }

    public function registerReservationEffect(MemberActivationEffectPermit $permit, MemberActivationInspection $inspection,
        MembershipReservations $reservations, MembershipActivationCapture $capture,
        MembershipRows $rows, array $plan): void
    {
        $entry = self::$effectPermits[$permit] ?? null;
        MemberGrantException::require($entry === [$this, $inspection, $this->grants, $rows, 'planning']
            && $reservations === $this->reservations, 'foreign_effect_permit');
        self::$effectPermits[$permit] = [$reservations, $capture, $rows, $rows->identity(), $plan];
    }

    public static function consumeReservationEffect(MemberActivationEffectPermit $permit, MembershipReservations $reservations,
        MembershipActivationCapture $capture, MembershipRows $rows, array $plan): void
    {
        $entry = self::$effectPermits[$permit] ?? null;
        if (self::$effectPermits !== null) {
            unset(self::$effectPermits[$permit]);
        }
        MemberGrantException::require($entry === [$reservations, $capture, $rows, $rows->identity(), $plan], 'foreign_effect_permit');
    }

    public function grantInspection(SealedMemberGrants $grants, MembershipRows $rows): MemberActivationInspection
    {
        $entry = $this->active[$rows] ?? null;
        MemberGrantException::require($grants === $this->grants && is_array($entry) && $entry['phase'] === 'current'
            && $entry['inspection'] instanceof MemberActivationInspection, 'foreign_grant_plan');

        return $entry['inspection'];
    }

    /** Consume completion-only provisional admission; it grants no public readiness or write. */
    public function consumeArtifactPlanPermit(MemberActivationArtifactPermit $permit, MemberOriginalArtifacts $artifacts,
        MemberActivationInspection $inspection, MemberOriginalMaterialization $materialized, MemberGrantIntent $intent,
        MemberGrantFactsProof $facts, MemberOriginalSourceValues $values, ProductionCustomerPrincipal $principal, User $actor,
        MembershipRows $rows, MemberOriginalBudget $budget): void
    {
        $entry = self::$artifactPlanPermits[$permit] ?? null;
        if (self::$artifactPlanPermits !== null) {
            unset(self::$artifactPlanPermits[$permit]);
        }
        MemberGrantException::require(is_array($entry) && $entry === [$this, $artifacts, $inspection, $materialized,
            $intent, $facts, $values, $principal, $actor, $rows, $rows->identity(), $budget], 'foreign_artifact_plan');
        $active = $this->active[$rows] ?? null;
        MemberGrantException::require($artifacts === $this->artifacts && is_array($active)
            && $active['inspection'] === $inspection && $active['phase'] === 'current', 'foreign_artifact_plan');
        MemberGrantException::require($inspection->artifactPlanSources($this) === [$intent, $facts, $values], 'foreign_artifact_plan');
        $budget->assertCurrent();
    }

    private function resolveIdle(): void
    {
        DB::connection()->getPdo();
        foreach (DB::getConnections() as $connection) {
            $connection->getPdo();
            $connection->getReadPdo();
            $connection->getDirectPdo();
        }
        MemberOriginalIoFrame::captureIdle()->assertStillIdle();
    }

    public function retireWorkflow(MemberOriginalBudget $budget): void
    {
        unset($this->workflows[$budget]);
        foreach ($this->sources as $source => $entry) {
            if ($entry[1] === $budget) {
                unset($this->sources[$source]);
            }
        }
        foreach ($this->claims as $claim => $entry) {
            if ($entry[1] === $budget) {
                unset($this->claims[$claim]);
            }
        }
        if ($this->completedReadbacks !== null) {
            foreach ($this->completedReadbacks as $readback => $entry) {
                if ($entry[8] === $budget) {
                    unset($this->completedReadbacks[$readback]);
                }
            }
        }
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_source_issuer');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_source_issuer');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_source_issuer');
    }

    public function __debugInfo(): array
    {
        return ['member_original_source_issuer' => true];
    }
}
