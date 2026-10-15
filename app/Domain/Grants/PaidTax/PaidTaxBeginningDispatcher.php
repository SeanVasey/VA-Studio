<?php

namespace App\Domain\Grants\PaidTax;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\TransactionBeginning;
use LogicException;
use PDO;

/** Retains one exact original birth before ordinary begun delegates; never a commit observer. */
final class PaidTaxBeginningDispatcher implements Dispatcher, \JsonSerializable
{
    private bool $captured = false;

    private function __construct(
        private readonly Connection $connection,
        private readonly PDO $primary,
        private readonly Dispatcher $delegate,
        private readonly Closure $retain,
    ) {}

    public static function install(PaidTaxRuntime $runtime, Closure $retain): self
    {
        $delegate = $runtime->connection->getEventDispatcher();
        PaidTaxException::require($delegate instanceof Dispatcher && ! $delegate instanceof self);
        $observer = new self($runtime->connection, $runtime->primary, $delegate, $retain);
        $runtime->connection->setEventDispatcher($observer);

        return $observer;
    }

    public function releaseOwned(): void
    {
        if ($this->connection->getEventDispatcher() === $this) {
            $this->connection->setEventDispatcher($this->delegate);
        }
    }

    public function dispatch($event, $payload = [], $halt = false)
    {
        if ($event instanceof TransactionBeginning && $event::class === TransactionBeginning::class
            && $event->connection === $this->connection) {
            PaidTaxException::require(! $this->captured && $this->connection->getEventDispatcher() === $this);
            // Laravel has just opened the PDO/root record. Retain its native anchor BEFORE callbacks,
            // independently of policy/deadline that those callbacks can withdraw or exhaust.
            $frame = PaidTaxOwnedFrame::capture($this->connection, $this->primary);
            $this->captured = true;
            ($this->retain)($frame);
        }

        return $this->delegate->dispatch($event, $payload, $halt);
    }

    public function listen($events, $listener = null)
    {
        $this->delegate->listen($events, $listener);
    }

    public function hasListeners($eventName)
    {
        return $this->delegate->hasListeners($eventName);
    }

    public function subscribe($subscriber)
    {
        return $this->delegate->subscribe($subscriber);
    }

    public function until($event, $payload = [])
    {
        return $this->dispatch($event, $payload, true);
    }

    public function push($event, $payload = [])
    {
        $this->delegate->push($event, $payload);
    }

    public function flush($event)
    {
        $this->delegate->flush($event);
    }

    public function forget($event)
    {
        $this->delegate->forget($event);
    }

    public function forgetPushed()
    {
        $this->delegate->forgetPushed();
    }

    private function __clone() {}

    public function __serialize(): never
    {
        throw new LogicException('Private Tax transaction birth observers cannot be serialized.');
    }

    public function __unserialize(array $data): never
    {
        throw new LogicException('Private Tax transaction birth observers cannot be deserialized.');
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Private Tax transaction birth observers are internal server objects.');
    }
}
