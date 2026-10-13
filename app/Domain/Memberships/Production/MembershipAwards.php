<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionCustomerPrincipal;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Memberships\Billing\BillingPolicy;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Throwable;
use WeakMap;

/** Unmounted/default-off owned first-period award with a post-callback durable receipt. */
final class MembershipAwards
{
    /** Only award() can mint an entry, immediately before its one constructor invocation. */
    private static ?WeakMap $contextPermits = null;

    /** Context construction consumes its exact owned invocation before any source callback or write. */
    public static function consumeContextPermit(MembershipRows $rows, MembershipAwardConfiguration $configuration,
        MembershipPolicy $policy, array $membershipConfiguration, BillingPolicy $billingPolicy, array $billingConfiguration,
        ProductionCustomerPrincipal $principal, User $actor, int $deadline, string $locator): void
    {
        $permit = self::$contextPermits[$rows] ?? null;
        if (self::$contextPermits !== null) {
            unset(self::$contextPermits[$rows]);
        }
        MembershipException::require($permit === [$rows->identity(), $configuration, $policy, $membershipConfiguration,
            $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $locator], 'owned_invocation_required');
    }

    public function award(string $locator, ProductionCustomerPrincipal $principal, User $actor): array
    {
        // The one original budget includes source callbacks, lock waits, commit events and receipt.
        $deadline = hrtime(true) + 10_000_000_000;
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
        $events = $connection->getEventDispatcher();
        $originalManager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        MembershipException::require($originalManager === null || get_class($originalManager) === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new DatabaseTransactionsManager;
        $observer = new MembershipAwardObserver($connection, $primary, $configuration, $events, $manager);
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        try {
            $connection->beginTransaction();
            $rows = $observer->rows();
            self::$contextPermits ??= new WeakMap;
            self::$contextPermits[$rows] = [$rows->identity(), $configuration, $policy, $membershipConfiguration,
                $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $locator];
            try {
                $context = new MembershipAwardContext($rows, $configuration, $policy, $membershipConfiguration,
                    $billingPolicy, $billingConfiguration, $principal, $actor, $deadline, $locator);
            } finally {
                // A failed construction must never leave authority for another invocation.
                unset(self::$contextPermits[$rows]);
            }
            $projection = $context->projection();
            $observer->prepare($context);
            // PDO commit, scoped afterCommit callbacks and ordinary committed delegates all run.
            $connection->commit();
            $observer->requireProof();
        } catch (Throwable $error) {
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

                return $projection;
            } finally {
                $frame->close();
            }
        } catch (Throwable) {
            // The regular write may already be durable. This is a refused response, never a retry.
            throw new MembershipException('committed_receipt_refused');
        }
    }
}
