<?php

namespace App\Domain\Memberships\Production;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use PDO;

/**
 * The owned operation's callback manager, which also witnesses its exact physical commit.
 *
 * Connection::commit() calls PDO::commit() and only then this manager's commit(), which runs the ordinary
 * afterCommit callbacks before the `committed` event. Reaching commit() for the armed connection's outermost
 * level, with the captured primary idle at transaction level zero, therefore proves that the owned PDO commit
 * returned. The witness is recorded before any afterCommit callback runs, so a callback or committed delegate
 * that throws can no longer turn a durable effect into a generic pre-commit refusal. An idle PDO observed later
 * is not this proof: a failed or foreign commit never reaches this point for the armed frame.
 */
final class MembershipCommitWitness extends DatabaseTransactionsManager
{
    private ?Connection $connection = null;

    private ?PDO $primary = null;

    private bool $committed = false;

    /** Arm immediately before the owned Connection::commit(); earlier or nested commits are never witnessed. */
    public function arm(Connection $connection, PDO $primary): void
    {
        MembershipException::require($this->connection === null && ! $this->committed
            && $connection->getRawPdo() === $primary && $connection->transactionLevel() === 1 && $primary->inTransaction()
            && $primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION, 'changed_transaction');
        $this->connection = $connection;
        $this->primary = $primary;
    }

    public function commit($connection, $levelBeingCommitted, $newTransactionLevel)
    {
        if ($this->connection !== null && ! $this->committed && $connection === $this->connection->getName()
            && $levelBeingCommitted === 1 && $newTransactionLevel === 0
            && $this->connection->transactionLevel() === 0 && $this->connection->getRawPdo() === $this->primary
            && ! $this->primary->inTransaction() && $this->primary->getAttribute(PDO::ATTR_ERRMODE) === PDO::ERRMODE_EXCEPTION) {
            $this->committed = true;
        }

        return parent::commit($connection, $levelBeingCommitted, $newTransactionLevel);
    }

    /** True only after the armed frame's own physical commit returned; callbacks may still have failed after it. */
    public function physicallyCommitted(): bool
    {
        return $this->committed;
    }
}
