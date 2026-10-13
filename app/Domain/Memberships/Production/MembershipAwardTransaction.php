<?php

namespace App\Domain\Memberships\Production;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** One marker shared with MembershipRows, captured before ordinary beginning delegates. */
final class MembershipAwardTransaction
{
    private readonly string $marker;

    private readonly string $database;

    private readonly string $name;

    public function __construct(private readonly Connection $connection, private readonly PDO $primary)
    {
        $this->database = $connection->getDatabaseName();
        $this->name = $connection->getName();
        $this->source();
        $this->marker = 'va_membership_award_'.bin2hex(random_bytes(16));
        $primary->exec('SAVEPOINT '.$this->marker);
    }

    public function admit(PDO $primary): void
    {
        MembershipException::require($primary === $this->primary, 'changed_primary');
        $this->source();
        try {
            $primary->exec('RELEASE SAVEPOINT '.$this->marker);
            $primary->exec('SAVEPOINT '.$this->marker);
        } catch (Throwable) {
            throw new MembershipException('changed_transaction');
        }
    }

    /** Never roll back a callback-created replacement transaction. No callbacks precede ownership. */
    public function protectFailure(): bool
    {
        $owned = false;
        if ($this->primary->inTransaction()) {
            try {
                $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
                $owned = true;
            } catch (Throwable) {
                // Losing the original marker means the current frame belongs to somebody else.
            }
        }
        $cached = $this->connection->getRawPdo();
        if ($owned && $cached === $this->primary && $this->connection->transactionLevel() === 1) {
            return true;
        }
        if ($owned) {
            $this->primary->rollBack();
        }
        $this->connection->setPdo($cached);

        return false;
    }

    private function source(): void
    {
        MembershipAwardConfiguration::plainPrimary($this->primary);
        MembershipException::require($this->connection->getRawPdo() === $this->primary
            && $this->connection->transactionLevel() === 1 && $this->primary->inTransaction()
            && DB::getDefaultConnection() === $this->name
            && (DB::getConnections()[$this->name] ?? null) === $this->connection
            && $this->connection->getDatabaseName() === $this->database && $this->connection->getTablePrefix() === ''
            && ($this->connection->getDriverName() !== 'mysql'
                || $this->primary->query('SELECT DATABASE()')->fetchColumn() === $this->database), 'changed_transaction');
    }
}
