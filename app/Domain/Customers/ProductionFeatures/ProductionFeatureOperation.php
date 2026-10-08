<?php

namespace App\Domain\Customers\ProductionFeatures;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\Listening\ListeningException;
use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureAccess;
use App\Domain\Customers\ProductionIdentity\Features\ProductionAccountFeatureIdentity;
use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Throwable;

/** A regular durable transaction followed by read-only validation of its original authority. */
final class ProductionFeatureOperation
{
    /** Contexts whose minting run callback is executing now. Only run() writes it; there is no setter. */
    private static array $live = [];

    /** Sealed consumers accept only a context minted by run() whose callback is still executing. */
    public static function assertLive(ProductionFeatureContext $context): void
    {
        if ((self::$live[spl_object_id($context)] ?? null) !== $context) {
            throw new ProductionFeatureException;
        }
    }

    public function run(ProductionAccountFeatureIdentity $identity, callable $operation): array
    {
        // One operation budget includes source callbacks, commit events and validation; never renewed.
        $deadline = hrtime(true) + 10_000_000_000;
        $configuration = new ProductionFeatureConfiguration;
        $connection = DB::connection();
        $primary = $connection->getPdo();
        foreach (DB::getConnections() as $sourceConnection) {
            $sourcePrimary = $sourceConnection->getPdo();
            ProductionFeatureConfiguration::plainPrimary($sourcePrimary);
            if ($sourceConnection->transactionLevel() !== 0 || $sourcePrimary->inTransaction()) {
                throw new ProductionFeatureException;
            }
        }
        $access = new ProductionAccountFeatureAccess;
        $configuration->admit();
        if (DB::connection() !== $connection || $connection->getRawPdo() !== $primary) {
            throw new ProductionFeatureException;
        }
        $events = $connection->getEventDispatcher();
        $managerProperty = new ReflectionProperty(Connection::class, 'transactionsManager');
        $originalManager = $managerProperty->getValue($connection);
        if ($originalManager !== null && get_class($originalManager) !== DatabaseTransactionsManager::class) {
            throw new ProductionFeatureException;
        }
        // Standard Laravel bookkeeping scoped to this operation: aborted frames cannot leak
        // queued callbacks into a later transaction, and other connections are untouched.
        $manager = new DatabaseTransactionsManager;
        $observer = new ProductionFeatureTransactionObserver($connection, $primary, $configuration, $events, $manager);
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        try {
            $connection->beginTransaction();
            $frame = $observer->frame();
            $reader = new CurrentRows($primary, $connection->getDriverName());
            $context = ProductionFeatureContext::locked($identity, $access, $reader, $frame, $deadline, $configuration);
            self::$live[spl_object_id($context)] = $context;
            try {
                $projection = $operation($context);
            } finally {
                unset(self::$live[spl_object_id($context)]);
            }
            $observer->prepare($context);
            // Normal Laravel committing/afterCommit/committed dispatch; observer proves after delegates.
            $connection->commit();
            $observer->requireProof();
        } catch (Throwable $error) {
            try {
                $observer->abort();
            } catch (Throwable) {
                throw new ProductionFeatureException;
            }
            if ($error instanceof ProductionFeatureException || $error instanceof IdentityException
                || $error instanceof ListeningException || $error instanceof ConsentException) {
                throw $error;
            }
            throw new ProductionFeatureException;
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
            $context->admitConfiguration();
            ProductionFeatureConfiguration::plainPrimary($context->reader()->identityPrimary());
            $frame = IdentityCommittedFrame::begin($context->reader(), $deadline);
            try {
                $context->admitConfiguration();
                $access->lockCommitted($identity, $frame, $context->authority());
                $context->proveCommitted($frame);
                // Consumes/closes the read-only physical frame. Only prebuilt plain output follows.
                $context->admitConfiguration();
                $access->proveCommitted($identity, $frame, $context->authority());

                return $projection;
            } finally {
                $frame->close();
            }
        } catch (Throwable) {
            // The regular write may already be durable. Never turn this into a retry/reset.
            throw new ProductionFeatureException;
        }
    }
}
