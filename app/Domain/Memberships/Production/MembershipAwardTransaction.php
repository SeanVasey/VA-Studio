<?php

namespace App\Domain\Memberships\Production;

use App\Domain\Customers\ProductionIdentity\SchemaProofFrame;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;
use WeakReference;

/** One marker shared with MembershipRows, captured before ordinary beginning delegates. */
final class MembershipAwardTransaction
{
    private readonly string $marker;

    private readonly string $database;

    private readonly string $name;

    private readonly SchemaProofFrame $proofs;

    public function __construct(private readonly Connection $connection, private readonly PDO $primary)
    {
        $this->database = $connection->getDatabaseName();
        $this->name = $connection->getName();
        $this->source();
        $this->marker = 'va_membership_award_'.bin2hex(random_bytes(16));
        $primary->exec('SAVEPOINT '.$this->marker);
        // Schema proofs are remembered only while this marker proves the same transaction. The witness holds this
        // frame weakly, so the proofs end with it.
        $owner = WeakReference::create($this);
        $this->proofs = SchemaProofFrame::open($connection, $primary, static function () use ($owner, $primary): void {
            $frame = $owner->get();
            MembershipException::require($frame instanceof self, 'changed_transaction');
            $frame->admit($primary);
        });
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

    /** The owned terminal phase: from here to the commit every schema guard proves in full, as before any memo. */
    public function beginTerminalProof(): void
    {
        $this->proofs->terminal();
    }

    /** Never roll back a callback-created replacement transaction. No callbacks precede ownership. */
    public function protectFailure(): bool
    {
        $this->proofs->close();
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
