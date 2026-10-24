<?php

namespace App\Domain\Grants\Member;

use App\Domain\Memberships\Production\MembershipAwardTransaction;
use App\Domain\Memberships\Production\MembershipRows;
use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitting;
use PDO;
use ReflectionProperty;

/** Ordinary Laravel delegates run once before the owned authoring terminal proof. */
final class MemberGrantAuthoringObserver implements Dispatcher
{
    private ?MembershipAwardTransaction $transaction = null;

    private ?MembershipRows $rows = null;

    private ?Closure $proof = null;

    private bool $proved = false;

    public function __construct(private readonly Connection $connection, private readonly PDO $primary,
        private readonly ?Dispatcher $delegate, private readonly DatabaseTransactionsManager $manager) {}

    public function rows(): MembershipRows
    {
        $this->admit();
        MemberGrantException::require($this->rows !== null, 'transaction_required');

        return $this->rows;
    }

    public function prepare(Closure $proof): void
    {
        $this->admit();
        MemberGrantException::require($this->proof === null, 'changed_transaction');
        $this->proof = $proof;
    }

    public function requireProof(): void
    {
        MemberGrantException::require($this->proved, 'commit_not_proved');
    }

    public function abort(): void
    {
        if ($this->transaction !== null && $this->transaction->protectFailure()) {
            $this->connection->rollBack();
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        $level = (new ReflectionProperty(Connection::class, 'transactions'))->getValue($this->connection);
        $beginning = ! $this->proved && $event instanceof TransactionBeginning && $event->connection === $this->connection && $level === 1;
        $committing = ! $this->proved && $event instanceof TransactionCommitting && $event->connection === $this->connection && $level === 1;
        if ($beginning) {
            $this->admit();
            MemberGrantException::require($this->transaction === null, 'changed_transaction');
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
            $this->admit();
            MemberGrantException::require($this->proof !== null && $this->transaction !== null, 'commit_not_proved');
            $this->transaction->beginTerminalProof();
            ($this->proof)();
            $this->admit();
            $this->proved = true;
        }

        return $result;
    }

    private function admit(): void
    {
        MemberGrantException::require((new ReflectionProperty(Connection::class, 'pdo'))->getValue($this->connection) === $this->primary
            && (new ReflectionProperty(Connection::class, 'events'))->getValue($this->connection) === $this
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
