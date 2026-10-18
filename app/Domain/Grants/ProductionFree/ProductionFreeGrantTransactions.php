<?php

namespace App\Domain\Grants\ProductionFree;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\DeadlockException;
use PDO;
use PDOException;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;
use Throwable;

/**
 * Every Free256 command runs in one of these. A deadlock (SQLSTATE 40001, MySQL 1213) or lock wait timeout (MySQL 1205)
 * rolls the whole transaction back, so nothing partial is written, and the caller gets the fixed reason `contention`
 * instead of a raw driver exception. There is deliberately no retry here: commands carry external effects (leased
 * claims, staged files, the one-use redemption row) and the customer-facing ones are idempotent by request key, so
 * the caller retrying the same command is the safe and observable path.
 *
 * The frame is owned (owner doc step 3): it must start at framework depth zero on an idle primary, and for its
 * duration the connection's dispatcher is a `ProductionFreeGrantCommitObserver` over a fresh transaction manager.
 * The work receives that observer and registers its terminal proof; ordinary committing listeners run first and the
 * proof re-runs immediately before the physical commit. A failure before the commit rolls back only the owned frame.
 * A failure after the physical commit cannot be a refusal of the write: it is the fixed `outcome_unknown`, never retried.
 * The original dispatcher and transaction manager are restored in every outcome.
 */
final class ProductionFreeGrantTransactions
{
    /** @param  callable(ProductionFreeGrantCommitObserver):mixed  $work */
    public static function run(callable $work): mixed
    {
        try {
            return self::owned($work);
        } catch (ProductionFreeGrantException $error) {
            throw $error;
        } catch (Throwable $error) {
            if (self::contended($error)) {
                throw new ProductionFreeGrantException('contention');
            }
            throw $error;
        }
    }

    /** True for a deadlock or lock wait timeout anywhere in the exception chain. */
    public static function contended(Throwable $error): bool
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof DeadlockException) {
                return true;
            }
            if ($current instanceof PDOException) {
                $info = is_array($current->errorInfo) ? $current->errorInfo : [];
                if (($info[0] ?? null) === '40001' || in_array((int) ($info[1] ?? 0), [1213, 1205], true) || $current->getCode() === '40001') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function owned(callable $work): mixed
    {
        $connection = ProductionFreeGrantRows::admittedConnection();
        $primary = $connection->getPdo();
        ProductionFreeGrantException::require(in_array($primary::class, [PDO::class, Sqlite::class, Mysql::class], true)
            && $primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class], 'changed_connection');
        // No outer frame: its commit would not be this command's commit.
        ProductionFreeGrantException::require($connection->transactionLevel() === 0 && ! $primary->inTransaction(), 'transaction_forbidden');
        $events = $connection->getEventDispatcher();
        $property = new ReflectionProperty(Connection::class, 'transactionsManager');
        $original = $property->getValue($connection);
        ProductionFreeGrantException::require($original === null || $original::class === DatabaseTransactionsManager::class, 'changed_transaction');
        $manager = new DatabaseTransactionsManager;
        $observer = new ProductionFreeGrantCommitObserver($connection, $primary, $events, $manager);
        $connection->setTransactionManager($manager);
        $connection->setEventDispatcher($observer);
        try {
            try {
                $connection->beginTransaction();
                $result = $work($observer);
                // Ordinary committing listeners, the terminal proof, the physical commit, scoped after-commit callbacks
                // and ordinary committed listeners all run inside this call.
                $connection->commit();
                $observer->requireProof();
                ProductionFreeGrantException::require($connection->transactionLevel() === 0 && ! $primary->inTransaction(), 'outcome_unknown');

                return $result;
            } catch (Throwable $error) {
                if ($observer->proved() && ! $primary->inTransaction()) {
                    // The physical commit already happened; a later callback or check cannot un-write it.
                    if ($connection->transactionLevel() !== 0) {
                        $connection->setPdo($connection->getRawPdo());
                    }
                    throw $error instanceof ProductionFreeGrantException && $error->reason === 'outcome_unknown'
                        ? $error : new ProductionFreeGrantException('outcome_unknown');
                }
                try {
                    $observer->abort();
                } catch (Throwable) {
                    throw new ProductionFreeGrantException('changed_transaction');
                }
                throw $error;
            }
        } finally {
            if ($events === null) {
                $connection->unsetEventDispatcher();
            } else {
                $connection->setEventDispatcher($events);
            }
            if ($original === null) {
                $connection->unsetTransactionManager();
            } else {
                $connection->setTransactionManager($original);
            }
        }
    }
}
