<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Domain\Memberships\Billing\BillingSchema;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use PDO;
use ReflectionProperty;
use Throwable;
use WeakMap;

/** Unmounted/default-off owned reservation lifecycle with a post-callback durable receipt. */
final class MembershipReservationOperations
{
    /** Only the private owned runner mints an entry for one exact operation and constructor. */
    private static ?WeakMap $contextPermits = null;

    /** Context construction consumes its exact owned invocation before any source callback or write. */
    public static function consumeContextPermit(MembershipRows $rows, MembershipAwardConfiguration $configuration,
        MembershipPolicy $policy, array $membershipConfiguration, BillingPolicy $billingPolicy, array $billingConfiguration,
        ProductionCustomerPrincipal $principal, User $actor, int $deadline, string $operation, string $locator, array $arguments): void
    {
        $permit = self::$contextPermits[$rows] ?? null;
        if (self::$contextPermits !== null) {
            unset(self::$contextPermits[$rows]);
        }
        MembershipException::require($permit === [$rows->identity(), $configuration, $policy, $membershipConfiguration,
            $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $operation, $locator, $arguments], 'owned_invocation_required');
    }

    public function reserve(string $locator, string $selection, int $expectedRevision, string $requestKey,
        ProductionCustomerPrincipal $principal, User $actor): array
    {
        return $this->run('reserve', $locator, ['selection' => $selection,
            'expected_revision' => $expectedRevision, 'request_key' => $requestKey], $principal, $actor);
    }

    /** Owner-triggered cleanup after the retained honor deadline; the context proves that boundary. */
    public function release(string $locator, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): array
    {
        return $this->run('release', $locator, ['redemption_id' => $redemptionId], $principal, $actor);
    }

    public function expire(string $locator, ProductionCustomerPrincipal $principal, User $actor): array
    {
        return $this->run('expire', $locator, [], $principal, $actor);
    }

    /**
     * Period-addressed entry points for the customer surface (C4d). The domain resolves the retained paid period's
     * billing-ledger invoice row itself, so a customer request carries only the period id the customer index lists.
     * The routing confers nothing: the owned context then locks and authenticates the invoice, owner, policy and
     * period afresh, and the result must name the requested period.
     */
    public function reserveInPeriod(string $periodId, string $selection, int $expectedRevision, string $requestKey,
        ProductionCustomerPrincipal $principal, User $actor): array
    {
        return $this->run('reserve', '', ['selection' => $selection,
            'expected_revision' => $expectedRevision, 'request_key' => $requestKey], $principal, $actor, $periodId);
    }

    public function releaseInPeriod(string $periodId, string $redemptionId, ProductionCustomerPrincipal $principal, User $actor): array
    {
        return $this->run('release', '', ['redemption_id' => $redemptionId], $principal, $actor, $periodId);
    }

    public function expireInPeriod(string $periodId, ProductionCustomerPrincipal $principal, User $actor): array
    {
        return $this->run('expire', '', [], $principal, $actor, $periodId);
    }

    private function run(string $operation, string $locator, array $arguments,
        ProductionCustomerPrincipal $principal, User $actor, ?string $period = null): array
    {
        // One original 60-second admission deadline includes callbacks, waits, commit and receipt.
        // Checks refuse elapsed authority; this does not guarantee interruption of a blocking wait.
        $deadline = hrtime(true) + 60_000_000_000;
        $lock = null;
        $primaryFailure = null;
        $committed = false;
        try {
            return $this->runOwned($operation, $locator, $arguments, $principal, $actor, $deadline, $lock, $committed, $period);
        } catch (Throwable $error) {
            $primaryFailure = $error;
            $committed = $committed || ($error instanceof MembershipException && $error->reason === 'committed_receipt_refused');
            throw $error;
        } finally {
            try {
                $lock?->closeAfterRefusal();
            } catch (Throwable $cleanup) {
                $previous = $primaryFailure === null ? $cleanup : new MembershipWorkflowCleanupFailure($primaryFailure, $cleanup);
                throw new MembershipException($committed ? 'committed_receipt_refused' : 'operation_refused', $previous);
            }
        }
    }

    private function runOwned(string $operation, string $locator, array $arguments,
        ProductionCustomerPrincipal $principal, User $actor, int $deadline, ?MembershipRedemptionWorkflowLock &$lock, bool &$committed,
        ?string $period): array
    {
        MembershipException::require(($period === null) !== ($locator === ''), 'invalid_value');
        $connection = DB::connection();
        $primary = $connection->getPdo();
        foreach (DB::getConnections() as $source) {
            $sourcePrimary = $source->getPdo();
            MembershipAwardConfiguration::plainPrimary($sourcePrimary);
            MembershipException::require($source->transactionLevel() === 0 && ! $sourcePrimary->inTransaction(), 'transaction_forbidden');
        }
        $policy = new MembershipPolicy;
        $membershipConfiguration = $policy->current();
        $billingPolicy = new BillingPolicy;
        $billingConfiguration = $billingPolicy->current();
        $configuration = new MembershipAwardConfiguration(requireComplete: true);
        // Resolve the full gate against the already-captured complete registration baseline.
        $policy->current();
        foreach (DB::getConnections() as $source) {
            $sourcePrimary = $source->getPdo();
            MembershipAwardConfiguration::plainPrimary($sourcePrimary);
            MembershipException::require($source->transactionLevel() === 0 && ! $sourcePrimary->inTransaction(), 'transaction_forbidden');
        }
        $configuration->admit();
        $policy->proveConfiguration($membershipConfiguration);
        $billingPolicy->proveConfiguration($billingConfiguration);
        MembershipException::require(DB::connection() === $connection && $connection->getRawPdo() === $primary, 'changed_primary');
        if ($operation === 'release') {
            // The same original ordinary deadline includes coordination; it is never restarted.
            $lock = MembershipRedemptionWorkflowLock::acquire($primary, $arguments['redemption_id'], $deadline);
        }
        $events = $connection->getEventDispatcher();
        $originalManager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        MembershipException::require($originalManager === null || get_class($originalManager) === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new MembershipCommitWitness;
        $observer = new MembershipReservationObserver($connection, $primary, $configuration, $events, $manager);
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        try {
            $connection->beginTransaction();
            $rows = $observer->rows();
            if ($period !== null) {
                $locator = self::periodLocator($rows, $period);
            }
            self::$contextPermits ??= new WeakMap;
            self::$contextPermits[$rows] = [$rows->identity(), $configuration, $policy, $membershipConfiguration,
                $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $operation, $locator, $arguments];
            try {
                $context = new MembershipReservationContext($rows, $configuration, $policy, $membershipConfiguration,
                    $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $operation, $locator, $arguments);
            } finally {
                // A failed construction must never leave authority for another invocation.
                unset(self::$contextPermits[$rows]);
            }
            $projection = $context->projection();
            MembershipException::require($period === null || ($projection['period_id'] ?? null) === $period, 'changed_period');
            $observer->prepare($context);
            $manager->arm($connection, $primary);
            // PDO commit, scoped afterCommit callbacks and ordinary committed delegates all run.
            $connection->commit();
            $committed = true;
            $observer->requireProof();
        } catch (Throwable $error) {
            if ($manager->physicallyCommitted()) {
                // A throwing afterCommit callback or committed delegate followed the witnessed owned commit.
                // The effect is durable; this is a refused response, never a retryable generic refusal.
                $committed = true;
                throw new MembershipException('committed_receipt_refused');
            }
            try {
                $observer->abort();
            } catch (Throwable) {
                throw new MembershipException('operation_refused');
            }
            if ($error instanceof MembershipException) {
                throw $error;
            }
            throw new MembershipException('operation_refused');
        } finally {
            if ($events === null) {
                $connection->unsetEventDispatcher();
            } else {
                $connection->setEventDispatcher($events);
            }
            if ($originalManager === null) {
                $connection->unsetTransactionManager();
            } else {
                $connection->setTransactionManager($originalManager);
            }
        }
        try {
            $configuration->admit();
            $frame = IdentityCommittedFrame::begin($context->reader(), $deadline);
            try {
                $context->proveCommitted($frame);
                if ($lock !== null) {
                    $lock->finish($deadline);
                    $context->admitAfterCoordination();
                }

                return $projection;
            } finally {
                $frame->close();
            }
        } catch (Throwable) {
            // The regular write may already be durable. This is a refused response, never a retry.
            throw new MembershipException('committed_receipt_refused');
        }
    }

    /** Nonlocking routing only, inside the owned frame; the context locks and authenticates every source afresh. */
    private static function periodLocator(MembershipRows $rows, string $periodId): string
    {
        MembershipValues::id($periodId);
        $rows->assertCurrent();
        (new BillingSchema)->assertOwned($rows->identity());
        $query = $rows->identity()->prepare('SELECT i.id invoice_id FROM '.(new MembershipSchema)->table(MembershipPeriodRecords::PERIODS)
            .' p JOIN '.(new BillingSchema)->table(BillingSchema::TABLES[1]).' i ON i.source_invoice_hash = p.source_invoice_hash WHERE p.id = ? LIMIT 2');
        $query->execute([$periodId]);
        $route = $query->fetchAll(PDO::FETCH_ASSOC);
        MembershipException::require(count($route) === 1 && is_string($route[0]['invoice_id']), 'paid_period_absent');
        MembershipValues::id($route[0]['invoice_id']);
        $rows->assertCurrent();

        return $route[0]['invoice_id'];
    }
}
