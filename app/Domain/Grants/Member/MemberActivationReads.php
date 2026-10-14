<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Production\MembershipRedemptionWorkflowLock;
use App\Domain\Memberships\Production\MembershipRows;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionProperty;
use Throwable;
use WeakMap;

/** Owner-permitted historical reads. No route here admits a source writer or regenerates an original. */
final class MemberActivationReads
{
    private WeakMap $active;

    private static ?WeakMap $inspectionPermits = null;

    public function __construct(private readonly MemberGrantActivations $owner, private readonly MemberRetainedOriginals $originals,
        private readonly MemberOriginalSourceCapture $sources)
    {
        $this->active = new WeakMap;
    }

    public function probe(MemberActivationReadPermit $permit, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget, array $expectedAuthorization): ?MemberGrantActivationResult
    {
        $lock = MemberGrantActivations::consumeReadPermit($permit, $this->owner, $this, $definitionId, $redemptionId,
            $principal, $actor, $budget, 'probe', $expectedAuthorization);

        return $this->execute($definitionId, $redemptionId, $principal, $actor, $budget, $expectedAuthorization, $lock, true);
    }

    public function read(MemberActivationReadPermit $permit, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget): MemberGrantActivationResult
    {
        MemberGrantActivations::consumeReadPermit($permit, $this->owner, $this, $definitionId, $redemptionId,
            $principal, $actor, $budget, 'read', null);
        $result = $this->execute($definitionId, $redemptionId, $principal, $actor, $budget, null);
        MemberGrantException::require($result !== null, 'activation_absent');

        return $result;
    }

    public function verify(MemberActivationReadPermit $permit, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget,
        MemberActivationCompletedReadback $readback): MemberGrantActivationResult
    {
        [$original, $state, $expected, $primary, $lock] = $this->sources->consumeCompletedReadback($permit, $this->owner,
            $this, $definitionId, $redemptionId, $principal, $actor, $budget, $readback);
        $budget->assertCurrent();
        $idle = MemberOriginalIoFrame::captureIdle();
        MemberGrantException::require($idle->identityPrimary() === $primary, 'changed_primary');
        $configuration = new MemberActivationReadConfiguration($this->owner);
        $idle->assertStillIdle();
        $budget->assertCurrent();
        $observation = $this->originals->observe($original, $idle, $budget);
        $this->originals->verify($original, $observation, $idle, $budget);
        $configuration->admit();
        $idle->assertStillIdle();
        [, $result] = $this->inspect($configuration, $definitionId, $redemptionId, $principal, $actor,
            $budget, null, $state, $original, $observation, $primary);
        MemberGrantException::require($result !== null && $result == $expected, 'changed_retained_effect');
        $this->finishRetainedTail($lock, $budget, $original, $observation, true);

        return $result;
    }

    private function execute(string $definitionId, string $redemptionId, ProductionCustomerPrincipal $principal,
        User $actor, MemberOriginalBudget $budget, ?array $expectedAuthorization,
        ?MembershipRedemptionWorkflowLock $lock = null, bool $activationOwned = false): ?MemberGrantActivationResult
    {
        // A current owner already implies a resolved primary. Never resolve lazy secondary handles
        // or adopt callback-created transactions before capturing this operation's baseline.
        $budget->assertCurrent();
        $idle = MemberOriginalIoFrame::captureIdle();
        $configuration = new MemberActivationReadConfiguration($this->owner);
        $idle->assertStillIdle();
        $budget->assertCurrent();
        [$original, $result, $state] = $this->inspect($configuration, $definitionId, $redemptionId, $principal,
            $actor, $budget, $expectedAuthorization, null, null, null);
        if ($original === null) {
            // Only the probe caller may turn this authenticated nonterminal absence into live admission.
            return null;
        }
        $observation = $this->originals->observe($original, $idle, $budget);
        $this->originals->verify($original, $observation, $idle, $budget);
        $configuration->admit();
        $idle->assertStillIdle();
        [, $final] = $this->inspect($configuration, $definitionId, $redemptionId, $principal,
            $actor, $budget, $expectedAuthorization, $state, $original, $observation);
        MemberGrantException::require($final !== null && $final == $result, 'changed_retained_effect');
        // No filesystem, producer, facade/config getter or permission refresh follows the final closure.
        $this->finishRetainedTail($lock, $budget, $original, $observation, $activationOwned);

        return $final;
    }

    private function finishRetainedTail(?MembershipRedemptionWorkflowLock $lock, MemberOriginalBudget $budget,
        MemberActivatedOriginal $original, MemberRetainedOriginalObservation $observation, bool $activationOwned): void
    {
        if (! $activationOwned) {
            $this->originals->assertCurrent($original, $observation, $budget);

            return;
        }
        try {
            $lock?->finish($budget->deadlineNs());
            $this->originals->assertCurrent($original, $observation, $budget);
        } catch (Throwable $error) {
            // The complete final retained proof already returned, including replay of a prior graph.
            throw new MemberGrantException('committed_activation_receipt_refused', previous: $error);
        }
    }

    private function inspect(MemberActivationReadConfiguration $configuration, string $definitionId, string $redemptionId,
        ProductionCustomerPrincipal $principal, User $actor, MemberOriginalBudget $budget, ?array $expectedAuthorization,
        ?array $expectedState, ?MemberActivatedOriginal $original, ?MemberRetainedOriginalObservation $observation,
        ?PDO $expectedPrimary = null): array
    {
        $deadline = min($budget->deadlineNs(), \hrtime(true) + 60_000_000_000);
        $idle = MemberOriginalIoFrame::captureIdle();
        $configuration->admit();
        $finish = MemberOriginalFinishBoundary::capture();
        $connection = DB::connection();
        $primary = $connection->getRawPdo();
        MemberGrantException::require($expectedPrimary === null || $primary === $expectedPrimary, 'changed_primary');
        $events = $connection->getEventDispatcher();
        $originalManager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        MemberGrantException::require($originalManager === null || get_class($originalManager) === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new DatabaseTransactionsManager;
        $observer = new MemberActivationReadObserver($connection, $primary, $configuration, $events, $manager);
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
                self::$inspectionPermits[$rows] = [$this, $configuration, $finish, $budget, $deadline, $definitionId,
                    $redemptionId, $principal, $actor, $expectedAuthorization, $expectedState, $this->originals, $original, $observation];
                try {
                    $inspection = new MemberActivationHistoryInspection($this, $rows, $configuration, $finish, $budget,
                        $deadline, $definitionId, $redemptionId, $principal, $actor, $expectedAuthorization, $expectedState,
                        $this->originals, $original, $observation);
                } finally {
                    unset(self::$inspectionPermits[$rows]);
                }
                $this->active[$inspection] = [$observer, false];
                $values = [$inspection->original(), $inspection->result(), $inspection->state()];
                $observer->prepare($inspection);
                $this->active[$inspection] = [$observer, true];
                try {
                    $proof = MemberActivationReadCommittedProof::register($this, $inspection, $observer);
                } finally {
                    $this->active[$inspection] = [$observer, false];
                }
                $connection->commit();
                $committed = true;
                $observer->requireCommittedOwnership();
            } catch (Throwable $error) {
                $observer->abort();
                if ($committed) {
                    throw new MemberGrantException('committed_activation_receipt_refused', previous: $error);
                }
                throw $error;
            } finally {
                if ($connection->getEventDispatcher() === $observer) {
                    $events === null ? $connection->unsetEventDispatcher() : $connection->setEventDispatcher($events);
                }
                if ((new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection) === $manager) {
                    $originalManager === null ? $connection->unsetTransactionManager() : $connection->setTransactionManager($originalManager);
                }
            }
            try {
                unset($this->active[$inspection]);
                $configuration->admit();
                $frame = IdentityCommittedFrame::begin($inspection->reader(), $deadline);
                try {
                    $proof->prove($frame);
                } finally {
                    $frame->close();
                }
            } catch (Throwable $error) {
                throw new MemberGrantException('committed_activation_receipt_refused', previous: $error);
            }
        } finally {
            if ($inspection !== null) {
                unset($this->active[$inspection]);
                $inspection->retire();
            }
            unset($proof, $inspection, $rows, $observer);
        }
        $configuration->admit();
        $budget->assertCurrent();
        $idle->assertStillIdle();
        if ($observation !== null) {
            $this->originals->assertCurrent($original, $observation, $budget);
        }

        return $values;
    }

    public static function consumeInspection(self $issuer, MembershipRows $rows, MemberActivationReadConfiguration $configuration,
        MemberOriginalFinishBoundary $finish, MemberOriginalBudget $budget, int $deadline, string $definitionId,
        string $redemptionId, ProductionCustomerPrincipal $principal, User $actor, ?array $expectedAuthorization,
        ?array $expectedState, MemberRetainedOriginals $originals, ?MemberActivatedOriginal $original,
        ?MemberRetainedOriginalObservation $observation): void
    {
        $permit = self::$inspectionPermits[$rows] ?? null;
        if (self::$inspectionPermits !== null) {
            unset(self::$inspectionPermits[$rows]);
        }
        MemberGrantException::require($permit === [$issuer, $configuration, $finish, $budget, $deadline, $definitionId,
            $redemptionId, $principal, $actor, $expectedAuthorization, $expectedState, $originals, $original, $observation], 'owned_inspection_required');
        $budget->assertCurrent();
        MemberGrantException::require(\hrtime(true) < $deadline, 'source_deadline');
    }

    public function admitCommittedRegistration(MemberActivationHistoryInspection $inspection, MemberActivationReadObserver $observer): void
    {
        MemberGrantException::require(($this->active[$inspection] ?? null) === [$observer, true], 'foreign_committed_proof');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_historical_reader');
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_historical_reader');
    }
}
