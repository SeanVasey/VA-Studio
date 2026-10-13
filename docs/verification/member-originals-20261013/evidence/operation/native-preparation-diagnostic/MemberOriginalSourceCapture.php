<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MemberGrantIntent;
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
        ProductionCustomerPrincipal $principal, User $actor, MembershipRows $rows): MemberOriginalInspection
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
        \Tests\Support\MemberOriginalNativeDiagnostic::emit('inspect.start', $deadline);
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
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.begin', $deadline);
                $rows = $observer->rows();
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.rows', $deadline);
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
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.inspection-values', $deadline);
                $observer->prepare($inspection);
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.observer-prepare', $deadline);
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
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.normal-commit', $deadline);
                $committed = true;
                $observer->requireCommittedOwnership();
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.normal-commit-owned', $deadline);
            } catch (Throwable $error) {
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('current.refusal', $deadline, $error);
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
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed-frame-begin', $deadline);
                try {
                    $proof->prove($frame);
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('after.committed-proof', $deadline);
                } finally {
                    $frame->close();
                }
            } catch (Throwable $error) {
                \Tests\Support\MemberOriginalNativeDiagnostic::emit('committed.refusal', $deadline, $error);
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
