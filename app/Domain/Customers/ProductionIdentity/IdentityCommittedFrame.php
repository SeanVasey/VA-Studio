<?php

namespace App\Domain\Customers\ProductionIdentity;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/** One-use read-only physical frame after normal Laravel commit/events. No identity or source writer. */
final class IdentityCommittedFrame
{
    private Connection $connection;

    private PDO $primary;

    private string $driver;

    private string $schema;

    private string $sentinel;

    private array $defaults;

    private bool $used = false;

    private bool $closed = false;

    private function __construct(private readonly CurrentRows $reader, private readonly int $originalDeadlineNs)
    {
        $this->connection = DB::connection();
        $this->primary = $reader->identityPrimary();
        $this->driver = $reader->identityDriver();
        $this->schema = $this->driver === 'sqlite' ? 'main' : $this->connection->getDatabaseName();
        $this->sentinel = 'va_identity_frame_'.bin2hex(random_bytes(16));
        $this->connectionCurrent();
        if ($this->primary->inTransaction() || hrtime(true) >= $originalDeadlineNs) {
            throw new IdentityException('committed_frame_required');
        }
        $this->defaults = $this->driver === 'sqlite' ? ['query_only' => (int) $this->primary->query('PRAGMA query_only')->fetchColumn()]
            : $this->primary->query('SELECT @@SESSION.transaction_isolation isolation_level,@@SESSION.transaction_read_only read_only')->fetch(PDO::FETCH_ASSOC);
        try {
            if ($this->driver === 'sqlite') {
                $this->primary->exec('PRAGMA query_only=ON');
            } else {
                // Applies to this next transaction only; the captured session defaults remain unchanged.
                $this->primary->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED, READ ONLY');
            }
            $this->primary->beginTransaction();
            $this->primary->exec('SAVEPOINT '.$this->sentinel);
            $this->assertActive();
        } catch (Throwable) {
            if ($this->primary->inTransaction()) {
                $this->primary->rollBack();
            }
            $this->restoreSqlite();
            throw new IdentityException('committed_frame_required');
        }
    }

    public static function begin(CurrentRows $reader, int $originalDeadlineNs): self
    {
        return new self($reader, $originalDeadlineNs);
    }

    public function reader(): CurrentRows
    {
        $this->assertActive();

        return $this->reader;
    }

    /** Current physical/session admission, without any framework query or commit event. */
    public function assertActive(): void
    {
        $this->connectionCurrent();
        if ($this->used || $this->closed || ! $this->primary->inTransaction() || hrtime(true) >= $this->originalDeadlineNs) {
            throw new IdentityException('committed_frame_required');
        }
        if ($this->driver === 'sqlite') {
            if ((int) $this->primary->query('PRAGMA query_only')->fetchColumn() !== 1) {
                throw new IdentityException('committed_frame_required');
            }
        } elseif ($this->primary->query('SELECT @@SESSION.transaction_isolation isolation_level,@@SESSION.transaction_read_only read_only')->fetch(PDO::FETCH_ASSOC) !== $this->defaults) {
            throw new IdentityException('committed_frame_required');
        }
    }

    /** Called inside the final pure identity proof, immediately before releasing only prebuilt output. */
    public function finish(): void
    {
        $this->assertActive();
        $this->used = true;
        $owned = false;
        try {
            // Commit/reopen loses this random savepoint even when PDO::inTransaction remains true.
            $this->primary->exec('RELEASE SAVEPOINT '.$this->sentinel);
            $owned = true;
            if (hrtime(true) >= $this->originalDeadlineNs) {
                $this->primary->rollBack();
                throw new IdentityException('committed_frame_required');
            }
            $this->primary->commit();
            $this->closed = true;
            $this->restoreSqlite();
            $this->connectionCurrent();
            if ($this->primary->inTransaction()) {
                throw new IdentityException('committed_frame_required');
            }
        } catch (Throwable) {
            if ($owned && $this->primary->inTransaction()) {
                $this->primary->rollBack();
                $this->closed = true;
                $this->restoreSqlite();
            }
            throw new IdentityException('committed_frame_required');
        }
    }

    /** Cleanup never rolls back a replacement/foreign transaction whose sentinel is absent. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->used = true;
        try {
            if ($this->primary->inTransaction()) {
                try {
                    $this->primary->exec('RELEASE SAVEPOINT '.$this->sentinel);
                    $this->primary->rollBack();
                } catch (Throwable) {
                    // Ownership is unknown after direct commit/reopen; do not touch that transaction.
                }
            }
        } finally {
            $this->restoreSqlite();
            $this->closed = true;
        }
    }

    private function connectionCurrent(): void
    {
        if (! in_array($this->driver, ['sqlite', 'mysql'], true) || $this->primary->getAttribute(PDO::ATTR_DRIVER_NAME) !== $this->driver
            || DB::connection() !== $this->connection || $this->connection->getPdo() !== $this->primary
            || $this->connection->getDriverName() !== $this->driver || $this->connection->transactionLevel() !== 0
            || ($this->driver === 'mysql' && ($this->connection->getDatabaseName() !== $this->schema
                || $this->primary->query('SELECT DATABASE()')->fetchColumn() !== $this->schema))) {
            throw new IdentityException('committed_frame_required');
        }
        foreach (DB::getConnections() as $connection) {
            if ($connection !== $this->connection && ($connection->transactionLevel() !== 0 || $connection->getPdo()->inTransaction())) {
                throw new IdentityException('committed_frame_required');
            }
        }
    }

    private function restoreSqlite(): void
    {
        if ($this->driver === 'sqlite' && isset($this->defaults['query_only'])) {
            $this->primary->exec('PRAGMA query_only='.(int) $this->defaults['query_only']);
        }
    }

    public function __serialize(): never
    {
        throw new IdentityException('committed_frame_required');
    }

    public function __debugInfo(): array
    {
        return ['read_only_frame' => true, 'used' => $this->used];
    }
}
