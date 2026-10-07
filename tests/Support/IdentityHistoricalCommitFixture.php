<?php

namespace Tests\Support;

use App\Domain\Commerce\ProductionPolicy\CurrentRows;
use App\Domain\Customers\ProductionIdentity\IdentityException;
use App\Domain\Customers\ProductionIdentity\IdentityHistoricalCommittedReceipt;
use App\Domain\Customers\ProductionIdentity\IdentityOriginalCommitWitness;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Real framework commit harness for identity-prefix tests; never a financial source or production observer. */
final class IdentityHistoricalCommitFixture extends Dispatcher
{
    private string $phase = 'held';

    private array $receipts = [];

    private array $seals = [];

    private string $anchor;

    private function __construct(private readonly Connection $connection, private readonly DispatcherContract $delegate,
        private readonly IdentityOriginalCommitWitness $witness, private readonly bool $swapSiblingSeals)
    {
        parent::__construct();
        $this->anchor = 'identity_producer_fixture_'.bin2hex(random_bytes(16));
        $connection->getRawPdo()->exec('SAVEPOINT '.$this->anchor);
    }

    public static function capture(array $binding, CurrentRows $reader, array $expected, int $deadline, int $count = 2, bool $swapSiblingSeals = false): self
    {
        $connection = DB::connection();
        $witness = IdentityOriginalCommitWitness::capture($reader, $deadline);
        $frame = new self($connection, $connection->getEventDispatcher(), $witness, $swapSiblingSeals);
        for ($index = 0; $index < $count; $index++) {
            $frame->receipts[] = IdentityHistoricalCommittedReceipt::capture($binding, $reader, $expected, $deadline, $witness);
        }
        // One legitimate observer is installed after witness capture, just as the producer contract requires.
        $connection->setEventDispatcher($frame);

        return $frame;
    }

    public function receipt(int $index): IdentityHistoricalCommittedReceipt
    {
        return $this->receipts[$index];
    }

    public function witness(): IdentityOriginalCommitWitness
    {
        return $this->witness;
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $owned = is_object($event) && property_exists($event, 'connection') && $event->connection === $this->connection;
        $committing = $owned && $event instanceof TransactionCommitting;
        $committed = $owned && $event instanceof TransactionCommitted;
        if ($owned && ($event instanceof TransactionBeginning || $event instanceof TransactionRolledBack
            || ($committing && $this->phase !== 'held') || ($committed && $this->phase !== 'prepared'))) {
            $this->invalidate();
        }
        try {
            $result = $this->delegate->dispatch($event, $payload, $halt);
            if ($committing) {
                if ($this->phase !== 'held') {
                    throw new IdentityException;
                }
                foreach ($this->receipts as $receipt) {
                    $this->seals[] = $receipt->sealOriginalCommit();
                }
                // The producer owns this original physical anchor; identity code creates none.
                $this->connection->getRawPdo()->exec('RELEASE SAVEPOINT '.$this->anchor);
                $this->witness->prepareOriginalCommit();
                $this->phase = 'prepared';
            } elseif ($committed) {
                if ($this->phase !== 'prepared') {
                    throw new IdentityException;
                }
                $this->witness->observeOriginalPositiveCommit();
                foreach ($this->receipts as $index => $receipt) {
                    $receipt->observeOriginalCommitted($this->seals[$this->swapSiblingSeals ? 1 - $index : $index]);
                }
                $this->phase = 'committed';
            }

            return $result;
        } catch (Throwable $error) {
            if ($committing || $committed) {
                $this->invalidate();
            }
            throw $error;
        }
    }

    public function invalidate(): void
    {
        $this->phase = 'invalid';
        $this->witness->invalidate();
    }

    public function restore(): void
    {
        $this->invalidate();
        if ($this->connection->getEventDispatcher() === $this) {
            $this->connection->setEventDispatcher($this->delegate);
        }
    }
}
