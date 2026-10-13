<?php

namespace App\Domain\Memberships\Production;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use PDO;
use ReflectionProperty;

/** Normal Laravel delegates run once, followed by the owned operation's terminal current proof. */
final class MembershipReservationObserver implements Dispatcher
{
    private ?MembershipAwardTransaction $transaction = null;

    private ?MembershipRows $rows = null;

    private ?MembershipReservationContext $context = null;

    private bool $proved = false;

    public function __construct(private readonly Connection $connection, private readonly PDO $primary,
        private readonly MembershipAwardConfiguration $configuration, private readonly ?Dispatcher $delegate,
        private readonly DatabaseTransactionsManager $manager) {}

    public function rows(): MembershipRows
    {
        $this->admit();
        MembershipException::require($this->rows !== null, 'transaction_required');
        $this->rows->assertCurrent();

        return $this->rows;
    }

    public function prepare(MembershipReservationContext $context): void
    {
        MembershipException::require($this->context === null && $context->reader()->identityPrimary() === $this->primary, 'changed_transaction');
        $this->admit();
        $this->rows()->assertCurrent();
        $this->context = $context;
    }

    public function requireProof(): void
    {
        MembershipException::require($this->proved, 'commit_not_proved');
    }

    public function abort(): void
    {
        if ($this->transaction !== null) {
            if ($this->transaction->protectFailure()) {
                $this->connection->rollBack();
            }
        } else {
            // A beforeStartingTransaction delegate can own this active source; no marker was taken.
            $this->connection->setPdo($this->connection->getRawPdo());
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $beginning = ! $this->proved && $event instanceof TransactionBeginning && $event->connection === $this->connection
            && $this->connection->transactionLevel() === 1;
        $committing = ! $this->proved && $event instanceof TransactionCommitting && $event->connection === $this->connection
            && $this->connection->transactionLevel() === 1;
        if ($beginning) {
            $this->admit();
            MembershipException::require($this->transaction === null, 'changed_transaction');
            // Capture before constructor/configuration work or the first ordinary beginning delegate.
            $this->transaction = new MembershipAwardTransaction($this->connection, $this->primary);
            $this->rows = new MembershipRows($this->transaction);
        }
        if ($committing) {
            $this->admit();
        }
        $result = $this->delegate?->dispatch($event, $payload, $halt);
        if ($beginning) {
            $this->rows()->assertCurrent();
        }
        if ($committing) {
            MembershipException::require($this->context !== null && ! $this->proved, 'commit_not_proved');
            $this->admit();
            $this->context->proveCurrent();
            $this->rows()->assertCurrent();
            $this->admit();
            $this->proved = true;
        }

        return $result;
    }

    private function admit(): void
    {
        $this->configuration->admit();
        MembershipAwardConfiguration::plainPrimary($this->primary);
        MembershipException::require($this->connection->getRawPdo() === $this->primary
            && $this->connection->getEventDispatcher() === $this
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
