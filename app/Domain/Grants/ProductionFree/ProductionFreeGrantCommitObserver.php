<?php

namespace App\Domain\Grants\ProductionFree;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use PDO;
use Pdo\Mysql;
use Pdo\Sqlite;
use PDOStatement;
use ReflectionProperty;
use Throwable;

/**
 * Connection-local commit observer for one Free256 command (owner doc step 3). It is the connection's dispatcher for
 * exactly one owned frame. Ordinary Laravel listeners still receive every event, unchanged. On the owned frame's
 * `TransactionCommitting` they run first; then, immediately before the physical commit, the command's own terminal
 * proof (current owner, policy and graph) and the permanent parent floor (F-4) are re-proved on the same primary.
 *
 * The frame carries a random savepoint taken before any ordinary beginning listener, so a callback's raw commit or
 * reopen is detected (the savepoint is gone even when PDO still reports a transaction). Detection cannot undo a raw or
 * implicit commit that already happened, so that outcome is reported as unknown, never as a refusal. A second frame on this
 * connection (nesting), a replaced dispatcher or transaction manager, or a frame without a registered terminal proof
 * refuses the commit. Nothing here retries or renews anything.
 */
final class ProductionFreeGrantCommitObserver implements Dispatcher
{
    private ?string $marker = null;

    private ?Closure $terminal = null;

    private bool $proved = false;

    private bool $refused = false;

    public function __construct(private readonly Connection $connection, private readonly PDO $primary,
        private readonly ?Dispatcher $delegate, private readonly DatabaseTransactionsManager $manager) {}

    /** Register the command's terminal proof once, from inside its work. It runs again after the ordinary committing listeners. */
    public function terminal(Closure $proof): void
    {
        ProductionFreeGrantException::require($this->terminal === null && ! $this->proved && ! $this->refused, 'changed_transaction');
        $this->admit();
        $this->terminal = $proof;
    }

    /** True once the terminal proof passed at the owned frame's commit; the physical commit follows it directly. */
    public function proved(): bool
    {
        return $this->proved;
    }

    public function requireProof(): void
    {
        ProductionFreeGrantException::require($this->proved && ! $this->refused, 'commit_not_proved');
    }

    /** True once the owned frame began and took its savepoint, so writes may have been issued inside it. */
    public function began(): bool
    {
        return $this->marker !== null;
    }

    /**
     * Roll back only the frame this observer owns, and say whether it did. A callback-created replacement transaction
     * (its savepoint is not ours) is never rolled back; the framework level is reset to idle without touching it.
     * `false` after the frame began means its fate is unknown: a raw or implicit commit by a callback may already have
     * made its writes durable. The observer can detect that, not prevent it.
     */
    public function abort(): bool
    {
        $owned = false;
        if ($this->primary->inTransaction()) {
            if ($this->marker === null) {
                // Refused at the beginning event before the marker: the frame run() just opened is still the only one.
                $owned = $this->connection->getRawPdo() === $this->primary && $this->connection->transactionLevel() === 1;
            } else {
                try {
                    $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
                    $owned = true;
                } catch (Throwable) {
                    // Losing the original marker means the current frame belongs to somebody else.
                }
            }
        }
        $cached = $this->connection->getRawPdo();
        if ($owned && $cached === $this->primary && $this->connection->transactionLevel() === 1) {
            $this->connection->rollBack();

            return true;
        }
        if ($owned) {
            $this->primary->rollBack();
        }
        $this->connection->setPdo($cached);

        return $owned;
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $owned = ($event instanceof TransactionBeginning || $event instanceof TransactionCommitting) && $event->connection === $this->connection;
        $beginning = $owned && $event instanceof TransactionBeginning;
        $committing = $owned && $event instanceof TransactionCommitting;
        if ($beginning) {
            // Exactly one frame, at depth one. Any later begin on this connection, including a framework savepoint, is nesting.
            if ($this->marker !== null || $this->proved || $this->connection->transactionLevel() !== 1) {
                $this->refused = true;
                throw new ProductionFreeGrantException('changed_transaction');
            }
            $this->source();
            // Captured before the first ordinary beginning listener can run.
            $marker = 'va_production_free_'.bin2hex(random_bytes(16));
            $this->primary->exec('SAVEPOINT '.$marker);
            $this->marker = $marker;
        }
        if ($committing) {
            if ($this->proved || $this->refused || $this->connection->transactionLevel() !== 1) {
                $this->refused = true;
                throw new ProductionFreeGrantException('commit_not_proved');
            }
            $this->admit();
        }
        $result = $this->delegate?->dispatch($event, $payload, $halt);
        if ($committing) {
            ProductionFreeGrantException::require($this->terminal !== null && ! $this->refused, 'commit_not_proved');
            $this->admit();
            // The command's terminal proof: graph first (it may run extensible source code), then the fixed owner and policy proofs.
            ($this->terminal)();
            // F-4: the permanent parents and the owned tables, including any session TEMPORARY shadow, immediately before commit.
            (new ProductionFreeGrantSchema)->assertOwned($this->primary);
            $this->admit();
            $this->proved = true;
        }

        return $result;
    }

    private function admit(): void
    {
        ProductionFreeGrantException::require($this->marker !== null && ! $this->refused, 'changed_transaction');
        $this->source();
        try {
            $this->primary->exec('RELEASE SAVEPOINT '.$this->marker);
            $this->primary->exec('SAVEPOINT '.$this->marker);
        } catch (Throwable) {
            throw new ProductionFreeGrantException('changed_transaction');
        }
    }

    private function source(): void
    {
        // Raw metadata first, so no getter below can run an offset or string-conversion callback.
        ProductionFreeGrantException::require(ProductionFreeGrantRows::admittedConnection() === $this->connection, 'changed_transaction');
        ProductionFreeGrantException::require(in_array($this->primary::class, [PDO::class, Sqlite::class, Mysql::class], true)
            && $this->primary->getAttribute(PDO::ATTR_STATEMENT_CLASS) === [PDOStatement::class]
            && $this->connection->getRawPdo() === $this->primary && $this->connection->transactionLevel() === 1
            && $this->primary->inTransaction() && $this->connection->getEventDispatcher() === $this
            && (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($this->connection) === $this->manager, 'changed_transaction');
    }

    public function listen($events, $listener = null)
    {
        $this->delegate?->listen($events, $listener);
    }

    public function hasListeners($eventName)
    {
        return $this->delegate?->hasListeners($eventName) ?? false;
    }

    public function subscribe($subscriber)
    {
        $this->delegate?->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function push($event, $payload = [])
    {
        $this->delegate?->push($event, $payload);
    }

    public function flush($event)
    {
        $this->delegate?->flush($event);
    }

    public function forget($event)
    {
        $this->delegate?->forget($event);
    }

    public function forgetPushed()
    {
        $this->delegate?->forgetPushed();
    }
}
